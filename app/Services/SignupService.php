<?php

namespace App\Services;

use App\Models\KermesseModel;
use App\Models\ProfileDivergenceModel;
use App\Models\SignupModel;
use App\Models\SlotModel;
use App\Models\StandModel;
use App\Models\UserModel;
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
 * Controllers must never call UserModel or SignupModel insert/update directly.
 */
class SignupService
{
    public function __construct(
        private readonly UserModel $userModel,
        private readonly SignupModel $signupModel,
        private readonly ?KermesseModel $kermesseModel = null,
        private readonly ?SlotModel $slotModel = null,
        private readonly ?ConnectionInterface $db = null,
        private readonly ?EmailService $emailService = null,
        private readonly ?StandModel $standModel = null,
        private readonly ?ProfileDivergenceModel $profileDivergenceModel = null,
        private readonly ?TokenService $tokenService = null,
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
        $email = strtolower(trim((string) ($fields['email'] ?? '')));

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
            || (string) $slot['ends_at'] < (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d H:i:s')) {
            return SignupResult::failure('slot_unavailable');
        }

        $activeCount = $this->signupModel->countActiveForSlot($slotId, $db);
        if ($activeCount >= (int) $slot['capacity']) {
            return SignupResult::failure('slot_full');
        }

        $userId = $this->findOrCreateUser($db, $email, $fields);
        if ($userId === null) {
            return SignupResult::failure('volunteer_insert_failed');
        }

        // One locking read does both jobs: it acquires the FOR UPDATE lock on the user
        // row (serializing same-user submissions before the duplicate/overlap checks,
        // which are themselves locking reads — see SignupModel) AND returns the stored
        // profile for divergence detection. A second lock on the same row would be redundant.
        $storedUser = $this->userModel->findByEmailHash($this->userModel->hashEmail($email), $db, true);

        if ($this->signupModel->findActiveByUserAndSlot($userId, $slotId, $db) !== null) {
            return SignupResult::failure('duplicate_signup');
        }

        $overlap = $this->signupModel->findOverlappingActiveByUser(
            $userId,
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
            'slot_id' => $slotId,
            'user_id' => $userId,
            'status'  => SignupModel::STATUS_ACTIVE,
        ]);

        if ($signupId === false) {
            return SignupResult::failure('signup_insert_failed');
        }

        // Record divergence if the submitted profile differs from the stored one.
        // Failure must not abort the signup (catch-all in recordProfileDivergence).
        if ($storedUser !== null && $this->detectsDivergence($storedUser, $fields)) {
            $this->recordProfileDivergence($db, $userId, (int) $signupId, $kermesseId, $fields);
        }

        // Internal result: the slot row rides in context so the caller can build
        // the confirmation email after commit; signup() rebuilds the public result.
        return new SignupResult(true, (int) $signupId, $userId, null, ['slot' => $slot]);
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

            // Token generation is isolated: failure must never abort the email send.
            $magicLinkUrl = '';
            try {
                $issued       = ($this->tokenService ?? new TokenService())->issueMagicLink($email, (int) $kermesse['id']);
                $magicLinkUrl = site_url('auth/magic-link/' . $issued->rawToken);
            } catch (\Throwable $e) {
                log_message('error', 'SignupService: magic link generation failed: ' . $e->getMessage());
            }

            $delivery = ($this->emailService ?? new EmailService())->sendSignupConfirmationEmail(
                $email,
                (string) ($fields['first_name'] ?? ''),
                (string) ($kermesse['name'] ?? ''),
                (string) ($stand['name'] ?? ''),
                (string) ($slot['starts_at'] ?? ''),
                (string) ($slot['ends_at'] ?? ''),
                $magicLinkUrl,
            );

            return $delivery->sent;
        } catch (\Throwable $e) {
            log_message('error', 'SignupService: confirmation email failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Find the global user by normalized email or create it, surviving the
     * concurrent-creation race on the uq_users_email_hash unique key.
     */
    private function findOrCreateUser(ConnectionInterface $db, string $email, array $fields): ?int
    {
        $emailHash = $this->userModel->hashEmail($email);

        $existing = $this->userModel->findByEmailHash($emailHash, $db);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        try {
            $inserted = $this->userModel->skipValidation(true)->insert([
                'email'      => $email,
                'email_hash' => $emailHash,
                'first_name' => (string) ($fields['first_name'] ?? ''),
                'last_name'  => (string) ($fields['last_name'] ?? ''),
                'phone'      => (string) ($fields['phone'] ?? ''),
            ]);

            if ($inserted !== false) {
                return (int) $inserted;
            }
        } catch (DatabaseException $e) {
            // A concurrent request won the insert race on the unique key. Manual
            // transaction mode keeps this transaction usable; fall through to the
            // locking re-read, which sees the competitor's committed row.
            if ($e->getCode() === 1062 || str_contains($e->getMessage(), 'Duplicate entry')) {
                log_message('info', 'User insert race, falling back to reuse: ' . $e->getMessage());
            } else {
                throw $e;
            }
        }

        $existing = $this->userModel->findByEmailHash($emailHash, $db, true);

        return $existing !== null ? (int) $existing['id'] : null;
    }

    /**
     * Locks live on $db; a model bound to a different connection would run its reads
     * and writes outside the transaction and silently void every invariant. Fail fast
     * instead — this is a wiring error, not a runtime condition.
     */
    private function assertSharedConnection(ConnectionInterface $db): void
    {
        // profileDivergenceModel writes inside the transaction too, so it must share $db.
        foreach ([$this->userModel, $this->signupModel, $this->profileDivergenceModel] as $model) {
            if ($model === null) {
                continue;
            }
            $modelDb = $model->db ?? null;
            if ($modelDb instanceof ConnectionInterface && $modelDb !== $db) {
                throw new DatabaseException(
                    'SignupService models must share the transaction connection.'
                );
            }
        }
    }

    /**
     * True when at least one of first_name, last_name, or phone differs between
     * the stored user record and the submitted signup fields.
     *
     * @param array<string, mixed> $storedUser
     * @param array<string, mixed> $fields
     */
    private function detectsDivergence(array $storedUser, array $fields): bool
    {
        if ((string) ($storedUser['first_name'] ?? '') !== (string) ($fields['first_name'] ?? '')) {
            return true;
        }
        if ((string) ($storedUser['last_name'] ?? '') !== (string) ($fields['last_name'] ?? '')) {
            return true;
        }
        // Phone is optional on the public form. DELIBERATE DECISION (review 3.4): a blank
        // submission is treated as "not provided / no change intended", never as a request
        // to erase the stored number. The frictionless public signup is not a profile
        // editor — clearing a contact field belongs to a dedicated edit surface — so a
        // volunteer who simply skips the phone must not silently wipe a number the
        // organisers rely on. Only a non-empty, different value records a divergence.
        $submittedPhone = (string) ($fields['phone'] ?? '');
        return $submittedPhone !== '' && $submittedPhone !== (string) ($storedUser['phone'] ?? '');
    }

    /**
     * Insert a profile_divergences row when submitted profile data differs from the
     * stored profile. Must never throw: any failure is logged and swallowed so the
     * surrounding transaction and signup can commit normally.
     */
    private function recordProfileDivergence(
        ConnectionInterface $db,
        int   $userId,
        int   $signupId,
        int   $kermesseId,
        array $fields,
    ): void {
        try {
            // Bind the fallback model to $db so the divergence row is written inside the
            // open transaction (a model on its own connection would escape it). An injected
            // model is validated up front by assertSharedConnection().
            ($this->profileDivergenceModel ?? new ProfileDivergenceModel($db))
                ->skipValidation(true)
                ->insert([
                    'user_id'              => $userId,
                    'kermesse_id'          => $kermesseId,
                    'signup_id'            => $signupId,
                    'submitted_first_name' => (string) ($fields['first_name'] ?? ''),
                    'submitted_last_name'  => (string) ($fields['last_name']  ?? ''),
                    'submitted_phone'      => (string) ($fields['phone']      ?? ''),
                ]);
        } catch (\Throwable $e) {
            log_message('error', 'SignupService: profile divergence record failed: ' . $e->getMessage());
        }
    }
}
