<?php

namespace App\Services;

use App\Models\KermesseModel;

use App\Models\SignupModel;
use App\Models\SlotModel;
use App\Models\StandModel;
use App\Models\UserModel;
use App\Models\UserRoleModel;
use App\Services\EmailDeliveryResult;
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

        private readonly ?TokenService $tokenService = null,
        private readonly ?UserRoleModel $userRoleModel = null,
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
     * Cancel (withdraw from) a volunteer's own signup — Story 4.3.
     *
     * Failure codes: not_found | signups_not_open | cancel_failed
     *
     * Ownership is enforced server-side: only the signup's owner may cancel it, and
     * only while the kermesse is open. Cancellation never needs a transaction or a
     * capacity check — freeing a place can never overbook — and the place is recovered
     * the instant the status becomes CANCELLED (every active count excludes it).
     */
    public function cancelSignup(int $signupId, int $userId, int $kermesseId): SignupResult
    {
        // Ownership + kermesse scope. A miss is reported neutrally so a volunteer
        // cannot probe other users' or other kermesses' signup ids.
        $signup = $this->signupModel->findActiveOwnedInKermesse($signupId, $userId, $kermesseId);
        if ($signup === null) {
            return SignupResult::failure('not_found');
        }

        // Lifecycle invariant: withdrawal is only allowed while signups are open
        // (AC2). Defence in depth — the dashboard hides the button when closed.
        $kermesse = ($this->kermesseModel ?? model(KermesseModel::class))->find($kermesseId);
        if ($kermesse === null || $kermesse['status'] !== KermesseModel::STATUS_OPEN) {
            return SignupResult::failure('signups_not_open');
        }

        if (! $this->signupModel->markCancelled($signupId, $userId)) {
            // The active row vanished between the read and the write (concurrent
            // cancel / double submit). Treat as a no-op failure, not a crash.
            return SignupResult::failure('cancel_failed');
        }

        return SignupResult::success($signupId, $userId);
    }

    /**
     * Stamp the modification-tracking columns on a signup row — Story 5.1.
     *
     * Called by admin actions (Stories 5.3, 5.10, 5.11, 5.12) after any correction
     * to a signup. $modifiedByUserId must be the admin/gestionnaire performing the
     * action — never the volunteer owner. The caller is responsible for running this
     * inside the surrounding transaction when one is active.
     *
     * Both columns default to NULL in the DB (migration 20260615093000) so signups
     * that have never been admin-edited are unambiguously distinguishable from edited
     * ones (AC3 of Story 5.1).
     *
     * Returns true when exactly one row was stamped.
     */
    public function stampAdminModification(int $signupId, int $modifiedByUserId): bool
    {
        return $this->signupModel->stampAdminModification($signupId, $modifiedByUserId);
    }

    /**
     * Cancel a signup on behalf of an admin — Story 5.10 AC1.
     *
     * Unlike the volunteer-facing cancelSignup(), this bypasses the kermesse lifecycle
     * check: admins can cancel any active signup regardless of kermesse status.
     * Failure codes: not_found | cancel_failed
     *
     * markCancelledByAdmin and stampAdminModification run in a single transaction so
     * both writes succeed or neither does (atomicity of the cancel + tracking stamp).
     *
     * If $notify is true and the cancellation succeeds, a cancellation email is sent
     * to the admin-corrected email address on the signup if available, falling back to
     * the volunteer's global profile address. Email failure is absorbed — it never rolls
     * back the cancellation (same pattern as signup confirmation, story 3.5 AC4).
     */
    public function adminCancelSignup(int $signupId, int $adminUserId, int $kermesseId, bool $notify = false): SignupResult
    {
        $signup = $this->signupModel->findActiveInKermesse($signupId, $kermesseId);
        if ($signup === null) {
            return SignupResult::failure('not_found');
        }

        $db = $this->db ?? db_connect();
        if (! $db->transBegin()) {
            return SignupResult::failure('cancel_failed');
        }

        try {
            if (! $this->signupModel->markCancelledByAdmin($signupId, $adminUserId)) {
                $db->transRollback();

                return SignupResult::failure('cancel_failed');
            }

            $this->signupModel->stampAdminModification($signupId, $adminUserId);

            if (! $db->transCommit()) {
                $db->transRollback();

                return SignupResult::failure('cancel_failed');
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'adminCancelSignup transaction failed: ' . $e->getMessage());

            return SignupResult::failure('cancel_failed');
        }

        if (! $notify) {
            return SignupResult::success($signupId, (int) $signup['user_id']);
        }

        // Use the admin-corrected email/name on the signup if set; fall back to the
        // volunteer's global profile (users table) when no correction has been made.
        $notifyEmail     = ((string) ($signup['signup_email']     ?? '')) !== ''
            ? (string) $signup['signup_email']
            : (string) ($signup['email']      ?? '');
        $notifyFirstName = ((string) ($signup['signup_first_name'] ?? '')) !== ''
            ? (string) $signup['signup_first_name']
            : (string) ($signup['first_name'] ?? '');

        $kermesse  = ($this->kermesseModel ?? model(KermesseModel::class))->find($kermesseId);
        $emailSent = $this->sendCancellationEmailSafely(
            email:        $notifyEmail,
            firstName:    $notifyFirstName,
            kermesseName: (string) ($kermesse['name'] ?? ''),
        );

        return SignupResult::success($signupId, (int) $signup['user_id'], $emailSent);
    }

    /**
     * Edit a signup's contact fields on behalf of an admin — Story 5.10 AC2/AC3.
     *
     * Writes only to signups.first_name/last_name/email/phone — NEVER to users.
     * Only allowed when the volunteer has not yet accessed this kermesse
     * (kermesse_user_roles.first_access_at IS NULL). Once the volunteer has
     * confirmed their identity at first access (Story 5.4), the profile is locked
     * and only they can change it via /profile.
     *
     * Email is normalized (lowercase + trim) here — not in the controller — so the
     * stored value is always canonical regardless of the caller.
     *
     * updateContactFields and stampAdminModification run in a single transaction so
     * both writes succeed or neither does.
     *
     * Failure codes: not_found | locked_profile | edit_failed
     *
     * @param array{first_name?: string, last_name?: string, email?: string, phone?: string} $fields
     */
    public function adminEditSignup(int $signupId, int $adminUserId, int $kermesseId, array $fields): SignupResult
    {
        $signup = $this->signupModel->findActiveInKermesse($signupId, $kermesseId);
        if ($signup === null) {
            return SignupResult::failure('not_found');
        }

        // Normalize email in the service so callers don't need to know about it.
        if (isset($fields['email']) && $fields['email'] !== '') {
            $fields['email'] = mb_strtolower(trim($fields['email']));
        }

        $db = $this->db ?? db_connect();
        if (! $db->transBegin()) {
            return SignupResult::failure('edit_failed');
        }

        try {
            if (! $this->signupModel->updateContactFields($signupId, $fields)) {
                $db->transRollback();

                return SignupResult::failure('edit_failed');
            }

            $this->signupModel->stampAdminModification($signupId, $adminUserId);

            if (! $db->transCommit()) {
                $db->transRollback();

                return SignupResult::failure('edit_failed');
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'adminEditSignup transaction failed: ' . $e->getMessage());

            return SignupResult::failure('edit_failed');
        }

        return SignupResult::success($signupId, (int) $signup['user_id']);
    }

    /**
     * Send the admin cancellation notification email. Every failure is absorbed.
     */
    private function sendCancellationEmailSafely(string $email, string $firstName, string $kermesseName): bool
    {
        if ($email === '') {
            return false;
        }

        try {
            $delivery = ($this->emailService ?? new EmailService())->sendSignupCancellationEmail(
                $email,
                $firstName,
                $kermesseName,
            );

            return $delivery->sent;
        } catch (\Throwable $e) {
            log_message('error', sprintf(
                'SignupService: cancellation email failed for %s (kermesse: %s): %s',
                $email,
                $kermesseName,
                $e->getMessage(),
            ));

            return false;
        }
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

        // Assign benevole role so the volunteer can access the dashboard (Story 4.1).
        // INSERT IGNORE: preserves any higher role (owner/admin/gestionnaire) if the
        // user was already a member of this kermesse before signing up as volunteer.
        $db->table('kermesse_user_roles')->ignore(true)->insert([
            'kermesse_id' => $kermesseId,
            'user_id'     => $userId,
            'role'        => UserRoleModel::ROLE_BENEVOLE,
        ]);



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
        foreach ([$this->userModel, $this->signupModel] as $model) {
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

}
