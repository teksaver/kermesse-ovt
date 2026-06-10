<?php

namespace App\Services;

use App\Models\KermesseModel;
use App\Models\SignupModel;
use App\Models\SlotModel;
use App\Models\StandModel;
use App\Models\VolunteerModel;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * Handles volunteer signup: validates kermesse state, slot state and capacity,
 * duplicate and overlap constraints, then finds or creates the volunteer and
 * inserts the signup row. All constraint checks and writes run in one manual
 * transaction on a single connection.
 *
 * INVARIANT: email is normalized (lowercase + trim) before any DB lookup or insert.
 *
 * CONCURRENCY: the slot row is locked FOR UPDATE before the first plain read so the
 * capacity count sees the latest committed state; the volunteer row is locked to
 * serialize same-volunteer submissions; the duplicate/overlap checks are locking
 * reads because plain reads under REPEATABLE READ would reuse this transaction's
 * stale snapshot. Cross-volunteer lock contention can surface as a deadlock victim:
 * MariaDB rolls one transaction back, which we map to transaction_failed (retryable).
 *
 * BOUNDARY: this service owns all signup state mutations and all business invariants.
 * Controllers must never call VolunteerModel or SignupModel insert/update directly.
 */
class SignupService
{
    public function __construct(
        private readonly VolunteerModel       $volunteerModel,
        private readonly SignupModel          $signupModel,
        private readonly ?KermesseModel       $kermesseModel = null,
        private readonly ?SlotModel           $slotModel = null,
        private readonly ?ConnectionInterface $db = null,
        private readonly ?EmailService        $emailService = null,
        private readonly ?StandModel          $standModel = null,
    ) {}

    /**
     * Validate all business invariants and, if satisfied, insert the signup.
     *
     * Failure codes: signups_not_open | slot_full | slot_unavailable | duplicate_signup
     *                overlap_conflict | volunteer_insert_failed | signup_insert_failed
     *                transaction_failed
     *
     * @param array<string, mixed> $fields  Validated: first_name, last_name, email, phone
     */
    public function signup(int $slotId, int $kermesseId, array $fields): SignupResult
    {
        $email = mb_strtolower(trim((string) ($fields['email'] ?? '')), 'UTF-8');

        if ($email === '') {
            return SignupResult::failure('volunteer_insert_failed');
        }

        // Pre-transaction: kermesse must be open (defense-in-depth; the form URL already
        // guards this — accepted trade-off, see story 3.4 review: the row is not re-read
        // under lock, so an admin closing concurrently may race one last signup through)
        $kermesse = ($this->kermesseModel ?? model(KermesseModel::class))->find($kermesseId);
        if ($kermesse === null || $kermesse['status'] !== KermesseModel::STATUS_OPEN) {
            return SignupResult::failure('signups_not_open');
        }

        $db = $this->db ?? db_connect();
        $this->assertSharedConnection($db);

        // Manual transaction mode: the recoverable duplicate-key failure on the volunteer
        // insert race must not doom the whole transaction, which transStart()'s automatic
        // status tracking would do.
        if (! $db->transBegin()) {
            return SignupResult::failure('transaction_failed');
        }

        try {
            $result = $this->signupWithinTransaction($db, $slotId, $kermesseId, $email, $fields);
        } catch (DatabaseException $e) {
            $db->transRollback();
            log_message('error', 'Signup transaction aborted: ' . $e->getMessage());

            return SignupResult::failure('transaction_failed');
        }

        if (! $result->success) {
            $db->transRollback();

            return $result;
        }

        if (! $db->transCommit()) {
            $db->transRollback();

            return SignupResult::failure('transaction_failed');
        }

        // Post-commit only: the signup is recorded no matter what happens to the
        // email — a delivery failure must never roll back or fail the inscription
        // (story 3.5 AC4). The slot row travels in the internal result context.
        $slot      = $result->context['slot'] ?? null;
        $emailSent = is_array($slot)
            ? $this->sendConfirmationEmailSafely($kermesse, $slot, $email, $fields)
            : false;

        return SignupResult::success((int) $result->signupId, (int) $result->volunteerId, $emailSent);
    }

    /**
     * Run the invariant checks and inserts. Never commits or rolls back: the caller
     * owns the transaction boundary (single rollback point, exception-safe).
     */
    private function signupWithinTransaction(
        ConnectionInterface $db,
        int $slotId,
        int $kermesseId,
        string $email,
        array $fields,
    ): SignupResult {
        // Lock the slot row so concurrent transactions serialize on the capacity check;
        // the count below is then this transaction's first plain read and its snapshot
        // includes every signup committed before the lock was granted.
        $slot = ($this->slotModel ?? model(SlotModel::class))->findForCapacityCheck($slotId, $db);
        if ($slot === null) {
            return SignupResult::failure('slot_full');
        }

        // The public summary already filters inactive/finished slots, but the service
        // owns the invariant: direct callers and admin-deactivation races must not
        // bypass it.
        if (($slot['status'] ?? null) !== SlotModel::STATUS_ACTIVE
            || (string) $slot['ends_at'] < date('Y-m-d H:i:s')) {
            return SignupResult::failure('slot_unavailable');
        }

        $activeCount = $this->signupModel->countActiveForSlot($slotId, $db);
        if ($activeCount >= (int) $slot['capacity']) {
            return SignupResult::failure('slot_full');
        }

        $volunteerId = $this->findOrCreateVolunteer($db, $kermesseId, $email, $fields);
        if ($volunteerId === null) {
            return SignupResult::failure('volunteer_insert_failed');
        }

        // Lock the volunteer row so same-volunteer submissions serialize before the
        // duplicate/overlap checks (which are locking reads — see SignupModel).
        $this->volunteerModel->lockForOverlapCheck($volunteerId, $db);

        if ($this->signupModel->findActiveByVolunteerAndSlot($volunteerId, $slotId, $db) !== null) {
            return SignupResult::failure('duplicate_signup');
        }

        $overlap = $this->signupModel->findOverlappingActiveByVolunteer(
            $volunteerId,
            (string) $slot['starts_at'],
            (string) $slot['ends_at'],
            $slotId,
            $db,
        );
        if ($overlap !== null) {
            return SignupResult::failure('overlap_conflict', [
                'conflicting_starts_at' => $overlap['starts_at'] ?? null,
                'conflicting_ends_at'   => $overlap['ends_at'] ?? null,
            ]);
        }

        $signupId = $this->signupModel->skipValidation(true)->insert([
            'slot_id'      => $slotId,
            'volunteer_id' => $volunteerId,
            'status'       => SignupModel::STATUS_ACTIVE,
        ]);

        if ($signupId === false) {
            return SignupResult::failure('signup_insert_failed');
        }

        // Internal result: the slot row rides in context so the caller can build
        // the confirmation email after commit; signup() rebuilds the public result.
        return new SignupResult(true, (int) $signupId, $volunteerId, null, ['slot' => $slot]);
    }

    /**
     * Send the confirmation email for a committed signup. Every failure mode —
     * send() returning false, view errors, SMTP exceptions — is absorbed here:
     * the caller only learns whether the email left (story 3.5 AC4).
     *
     * @param array<string, mixed> $kermesse
     * @param array<string, mixed> $slot
     * @param array<string, mixed> $fields
     */
    private function sendConfirmationEmailSafely(array $kermesse, array $slot, string $email, array $fields): bool
    {
        try {
            $stand = ($this->standModel ?? model(StandModel::class))->find((int) ($slot['stand_id'] ?? 0));

            $delivery = ($this->emailService ?? new EmailService())->sendSignupConfirmationEmail(
                $email,
                (string) ($fields['first_name'] ?? ''),
                (string) ($kermesse['name'] ?? ''),
                (string) ($stand['name'] ?? ''),
                (string) ($slot['starts_at'] ?? ''),
                (string) ($slot['ends_at'] ?? ''),
            );

            return $delivery->sent;
        } catch (\Throwable $e) {
            log_message('error', 'SignupService: confirmation email failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Find the volunteer for (kermesse, normalized email) or create it, surviving the
     * concurrent-creation race on the uq_volunteers_kermesse_email unique key.
     */
    private function findOrCreateVolunteer(ConnectionInterface $db, int $kermesseId, string $email, array $fields): ?int
    {
        $existing = $this->volunteerModel->findByKermesseAndEmail($kermesseId, $email, $db);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        try {
            $inserted = $this->volunteerModel->skipValidation(true)->insert([
                'kermesse_id' => $kermesseId,
                'first_name'  => (string) ($fields['first_name'] ?? ''),
                'last_name'   => (string) ($fields['last_name'] ?? ''),
                'email'       => $email,
                'phone'       => (string) ($fields['phone'] ?? ''),
            ]);

            if ($inserted !== false) {
                return (int) $inserted;
            }
        } catch (DatabaseException $e) {
            // A concurrent request won the insert race on the unique key. Manual
            // transaction mode keeps this transaction usable; fall through to the
            // locking re-read, which sees the competitor's committed row.
            log_message('info', 'Volunteer insert race, falling back to reuse: ' . $e->getMessage());
        }

        $existing = $this->volunteerModel->findByKermesseAndEmail($kermesseId, $email, $db, true);

        return $existing !== null ? (int) $existing['id'] : null;
    }

    /**
     * Locks live on $db; a model bound to a different connection would run its reads
     * and writes outside the transaction and silently void every invariant. Fail fast
     * instead — this is a wiring error, not a runtime condition.
     */
    private function assertSharedConnection(ConnectionInterface $db): void
    {
        foreach ([$this->volunteerModel, $this->signupModel] as $model) {
            $modelDb = $model->db ?? null;
            if ($modelDb instanceof ConnectionInterface && $modelDb !== $db) {
                throw new DatabaseException(
                    'SignupService models must share the transaction connection.'
                );
            }
        }
    }
}
