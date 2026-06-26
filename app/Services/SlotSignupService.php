<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\KermesseModel;
use App\Models\SlotSignupModel;
use App\Models\SlotModel;
use App\Models\StandModel;
use App\Models\UserModel;
use App\Models\UserRoleModel;
use App\Services\EmailDeliveryResult;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * Handles volunteer slot-signup: validates kermesse state, slot state and capacity,
 * duplicate and overlap constraints, then finds or creates the volunteer and
 * inserts the slot_signup row. All constraint checks and writes run in one manual
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
 * BOUNDARY: this service owns all slot-signup state mutations and all business invariants.
 * Controllers must never call UserModel or SlotSignupModel insert/update directly.
 */
class SlotSignupService
{
    public function __construct(
        private readonly UserModel $userModel,
        private readonly SlotSignupModel $slotSignupModel,
        private readonly ?KermesseModel $kermesseModel = null,
        private readonly ?SlotModel $slotModel = null,
        private readonly ?BaseConnection $db = null,
        private readonly ?EmailService $emailService = null,
        private readonly ?StandModel $standModel = null,
        private readonly ?TokenService $tokenService = null,
    ) {}

    /**
     * Validate all business invariants and, if satisfied, insert the slot-signup.
     *
     * Failure codes: signups_not_open | slot_full | slot_unavailable | duplicate_signup
     *                overlap_conflict | volunteer_insert_failed | signup_insert_failed
     *                transaction_failed
     *
     * @param array<string, mixed> $fields  Validated: first_name, last_name, email, phone
     */
    public function signup(int $slotId, int $kermesseId, array $fields, ?int $createdBy = null): SlotSignupResult
    {
        $email = strtolower(trim((string) ($fields['email'] ?? '')));

        if ($email === '') {
            return SlotSignupResult::failure('volunteer_insert_failed');
        }

        // Pre-transaction: kermesse must be open (defense-in-depth; the form URL already
        // guards this — accepted trade-off, see story 3.4 review: the row is not re-read
        // under lock, so an admin closing concurrently may race one last signup through)
        $kermesse = ($this->kermesseModel ?? model(KermesseModel::class))->find($kermesseId);
        if ($kermesse === null || $kermesse['status'] !== KermesseModel::STATUS_OPEN) {
            return SlotSignupResult::failure('signups_not_open');
        }

        $db = $this->db ?? db_connect();
        $this->assertSharedConnection($db);

        // Manual transaction mode: the recoverable duplicate-key failure on the volunteer
        // insert race must not doom the whole transaction, which transStart()'s automatic
        // status tracking would do.
        if (! $db->transBegin()) {
            return SlotSignupResult::failure('transaction_failed');
        }

        try {
            $result = $this->signupWithinTransaction($db, $slotId, $kermesseId, $email, $fields, $createdBy);
        } catch (DatabaseException $e) {
            $db->transRollback();
            log_message('error', 'SlotSignup transaction aborted: ' . $e->getMessage());

            return SlotSignupResult::failure('transaction_failed');
        }

        if (! $result->success) {
            $db->transRollback();

            return $result;
        }

        if (! $db->transCommit()) {
            $db->transRollback();

            return SlotSignupResult::failure('transaction_failed');
        }

        // Post-commit only: the signup is recorded no matter what happens to the
        // email — a delivery failure must never roll back or fail the inscription
        // (story 3.5 AC4). The slot row travels in the internal result context.
        $slot      = $result->context['slot'] ?? null;
        $emailSent = is_array($slot)
            ? $this->sendConfirmationEmailSafely($kermesse, $slot, $email, $fields)
            : false;

        return SlotSignupResult::success((int) $result->signupId, $result->volunteerId, $emailSent);
    }

    /**
     * Cancel (withdraw from) a volunteer's own slot-signup — Story 4.3.
     *
     * Failure codes: not_found | signups_not_open | cancel_failed
     *
     * Ownership is enforced server-side: only the signup's owner may cancel it, and
     * only while the kermesse is open. Cancellation never needs a transaction or a
     * capacity check — freeing a place can never overbook — and the place is recovered
     * the instant the status becomes CANCELLED (every active count excludes it).
     */
    public function cancelSlotSignup(int $slotSignupId, int $userId, int $kermesseId): SlotSignupResult
    {
        // Ownership + kermesse scope. A miss is reported neutrally so a volunteer
        // cannot probe other users' or other kermesses' slot-signup ids.
        $slotSignup = $this->slotSignupModel->findActiveOwnedInKermesse($slotSignupId, $userId, $kermesseId);
        if ($slotSignup === null) {
            return SlotSignupResult::failure('not_found');
        }

        // Confirmed slot-signups (accepted_at set) can always be cancelled — it is a
        // defensive action, not a new booking. Non-confirmed ones still require the
        // kermesse to be open. Defence in depth — the dashboard mirrors this rule (P2).
        if (empty($slotSignup['accepted_at'])) {
            $kermesse = ($this->kermesseModel ?? model(KermesseModel::class))->find($kermesseId);
            if ($kermesse === null || $kermesse['status'] !== KermesseModel::STATUS_OPEN) {
                return SlotSignupResult::failure('signups_not_open');
            }
        }

        if (! $this->slotSignupModel->markCancelled($slotSignupId, $userId)) {
            // The active row vanished between the read and the write (concurrent
            // cancel / double submit). Treat as a no-op failure, not a crash.
            return SlotSignupResult::failure('cancel_failed');
        }

        return SlotSignupResult::success($slotSignupId, $userId);
    }

    /**
     * Admin-initiated manual slot-signup — Story 5.11.
     *
     * Identical invariants to signup() (capacity, duplicate, overlap, slot status) with
     * one deliberate override: the kermesse lifecycle check is skipped so admins can
     * register volunteers even when the kermesse is "closed" (AC3).
     *
     * stampAdminModification runs inside the same transaction as the insert so
     * last_modified_by_user_id is committed atomically with the signup row.
     *
     * The optional confirmation email is sent post-commit and absorbed on failure
     * (same pattern as signup(), story 3.5 AC4).
     *
     * Failure codes (inherited from signupWithinTransaction):
     *   signups_not_open | slot_full | slot_unavailable | duplicate_signup
     *   overlap_conflict | volunteer_insert_failed | signup_insert_failed
     *   transaction_failed
     */
    public function createSlotSignupByAdmin(AdminCreateSlotSignupDTO $dto): SlotSignupResult
    {
        $email = strtolower(trim($dto->email));
        if ($email === '') {
            return SlotSignupResult::failure('volunteer_insert_failed');
        }

        // Admin override: kermesse must exist, but does NOT need to be "open" (AC3).
        $kermesse = ($this->kermesseModel ?? model(KermesseModel::class))->find($dto->kermesseId);
        if ($kermesse === null) {
            return SlotSignupResult::failure('signups_not_open');
        }

        $db = $this->db ?? db_connect();
        $this->assertSharedConnection($db);

        if (! $db->transBegin()) {
            return SlotSignupResult::failure('transaction_failed');
        }

        try {
            $result = $this->signupWithinTransaction($db, $dto->slotId, $dto->kermesseId, $email, [
                'first_name' => $dto->firstName,
                'last_name'  => $dto->lastName,
                'phone'      => $dto->phone,
            ], $dto->adminUserId);

            if (! $result->success) {
                $db->transRollback();

                return $result;
            }

            // Stamp admin tracking inside the same transaction (AC3 — last_modified_by_user_id).
            $this->slotSignupModel->stampAdminModification((int) $result->signupId, $dto->adminUserId);
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'createSlotSignupByAdmin transaction aborted: ' . $e->getMessage());

            return SlotSignupResult::failure('transaction_failed');
        }

        if (! $db->transCommit()) {
            $db->transRollback();

            return SlotSignupResult::failure('transaction_failed');
        }

        // Post-commit: optional confirmation email. Failure is absorbed (story 3.5 AC4).
        $slot      = $result->context['slot'] ?? null;
        $emailSent = null;

        if ($dto->sendConfirmationEmail && is_array($slot)) {
            $emailSent = $this->sendConfirmationEmailSafely($kermesse, $slot, $email, [
                'first_name' => $dto->firstName,
                'last_name'  => $dto->lastName,
            ]);
        }

        return SlotSignupResult::success((int) $result->signupId, $result->volunteerId, $emailSent);
    }

    /**
     * Stamp the modification-tracking columns on a slot-signup row — Story 5.1.
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
        return $this->slotSignupModel->stampAdminModification($signupId, $modifiedByUserId);
    }

    /**
     * Cancel a slot-signup on behalf of an admin — Story 5.10 AC1.
     *
     * Unlike the volunteer-facing cancelSlotSignup(), this bypasses the kermesse lifecycle
     * check: admins can cancel any active slot-signup regardless of kermesse status.
     * Failure codes: not_found | cancel_failed
     *
     * markCancelledByAdmin and stampAdminModification run in a single transaction so
     * both writes succeed or neither does (atomicity of the cancel + tracking stamp).
     *
     * If $notify is true and the cancellation succeeds, a cancellation email is sent
     * to the admin-corrected email address on the slot-signup if available, falling back
     * to the volunteer's global profile address. Email failure is absorbed — it never
     * rolls back the cancellation (same pattern as slot-signup confirmation, story 3.5 AC4).
     */
    public function adminCancelSlotSignup(int $slotSignupId, int $adminUserId, int $kermesseId, bool $notify = false): SlotSignupResult
    {
        $slotSignup = $this->slotSignupModel->findActiveInKermesse($slotSignupId, $kermesseId);
        if ($slotSignup === null) {
            return SlotSignupResult::failure('not_found');
        }

        $db = $this->db ?? db_connect();
        if (! $db->transBegin()) {
            return SlotSignupResult::failure('cancel_failed');
        }

        try {
            if (! $this->slotSignupModel->markCancelledByAdmin($slotSignupId, $adminUserId)) {
                $db->transRollback();

                return SlotSignupResult::failure('cancel_failed');
            }

            $this->slotSignupModel->stampAdminModification($slotSignupId, $adminUserId);

            if (! $db->transCommit()) {
                $db->transRollback();

                return SlotSignupResult::failure('cancel_failed');
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'adminCancelSlotSignup transaction failed: ' . $e->getMessage());

            return SlotSignupResult::failure('cancel_failed');
        }

        // Use the admin-corrected name/email on the slot-signup if set; fall back to users table.
        $notifyFirstName = ((string) ($slotSignup['signup_first_name'] ?? '')) !== ''
            ? (string) $slotSignup['signup_first_name']
            : (string) ($slotSignup['first_name'] ?? '');
        $notifyLastName  = (string) ($slotSignup['last_name'] ?? '');
        $notifyEmail     = ((string) ($slotSignup['signup_email'] ?? '')) !== ''
            ? (string) $slotSignup['signup_email']
            : (string) ($slotSignup['email'] ?? '');

        $volunteerName = trim($notifyFirstName . ' ' . $notifyLastName) ?: $notifyEmail;
        $slotLabel     = $this->formatSlotLabel(
            standName: $slotSignup['stand_name'],
            startsAt:  $slotSignup['starts_at'],
            endsAt:    $slotSignup['ends_at'],
        );

        $context     = ['volunteer_name' => $volunteerName, 'slot_label' => $slotLabel];
        $volunteerId = ($slotSignup['user_id'] !== null) ? (int) $slotSignup['user_id'] : null;

        if (! $notify) {
            return SlotSignupResult::success($slotSignupId, $volunteerId, null, $context);
        }

        $kermesse  = ($this->kermesseModel ?? model(KermesseModel::class))->find($kermesseId);
        $emailSent = $this->sendCancellationEmailSafely(
            email:        $notifyEmail,
            firstName:    $notifyFirstName,
            kermesseName: (string) ($kermesse['name'] ?? ''),
            slotLabel:    $slotLabel,
        );

        return SlotSignupResult::success($slotSignupId, $volunteerId, $emailSent, $context);
    }

    /**
     * Edit a slot-signup's contact fields on behalf of an admin — Story 5.10 AC2/AC3.
     *
     * Writes only to slot_signups.first_name/last_name/email/phone — NEVER to users.
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
    public function adminEditSlotSignup(int $slotSignupId, int $adminUserId, int $kermesseId, array $fields): SlotSignupResult
    {
        $slotSignup = $this->slotSignupModel->findActiveInKermesse($slotSignupId, $kermesseId);
        if ($slotSignup === null) {
            return SlotSignupResult::failure('not_found');
        }

        // Normalize email in the service; unset if empty to avoid overwriting with blank.
        if (isset($fields['email'])) {
            if ($fields['email'] === '') {
                unset($fields['email']);
            } else {
                $fields['email'] = mb_strtolower(trim($fields['email']));
            }
        }

        $db = $this->db ?? db_connect();
        if (! $db->transBegin()) {
            return SlotSignupResult::failure('edit_failed');
        }

        try {
            if (! $this->slotSignupModel->updateContactFields($slotSignupId, $fields)) {
                $db->transRollback();

                return SlotSignupResult::failure('edit_failed');
            }

            $this->slotSignupModel->stampAdminModification($slotSignupId, $adminUserId);

            if (! $db->transCommit()) {
                $db->transRollback();

                return SlotSignupResult::failure('edit_failed');
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'adminEditSlotSignup transaction failed: ' . $e->getMessage());

            return SlotSignupResult::failure('edit_failed');
        }

        return SlotSignupResult::success($slotSignupId, (int) $slotSignup['user_id']);
    }

    /**
     * Move a volunteer's slot-signup to a different slot — Story 5.12.
     *
     * Admin override: kermesse must exist but does NOT need to be "open".
     * All capacity, duplicate, and overlap invariants are enforced on the target slot.
     * The source signup is marked REMOVED and the new signup is created atomically.
     *
     * The overlap check naturally excludes the source slot because markCancelledByAdmin
     * runs inside the same transaction — locking reads (FOR UPDATE) see the REMOVED
     * status and exclude it from the active set before signupWithinTransaction runs.
     *
     * Failure codes: not_found | same_slot | slot_full | slot_unavailable
     *                duplicate_signup | overlap_conflict | transaction_failed
     */
    public function moveSlotSignup(AdminMoveSlotSignupDTO $dto): SlotSignupResult
    {
        $slotSignup = $this->slotSignupModel->findActiveInKermesse($dto->sourceSlotSignupId, $dto->kermesseId);
        if ($slotSignup === null) {
            return SlotSignupResult::failure('not_found');
        }

        if ((int) $slotSignup['slot_id'] === $dto->targetSlotId) {
            return SlotSignupResult::failure('same_slot');
        }

        $kermesse = ($this->kermesseModel ?? model(KermesseModel::class))->find($dto->kermesseId);
        if ($kermesse === null) {
            return SlotSignupResult::failure('signups_not_open');
        }

        // Resolve contact info: admin-corrected slot-signup copy takes precedence over users table.
        $email     = strtolower(trim(
            ((string) ($slotSignup['signup_email']      ?? '')) !== '' ? (string) $slotSignup['signup_email']      : (string) ($slotSignup['email']      ?? '')
        ));
        $firstName = trim(((string) ($slotSignup['signup_first_name'] ?? '')) !== '' ? (string) $slotSignup['signup_first_name'] : (string) ($slotSignup['first_name'] ?? ''));
        $lastName  = trim(((string) ($slotSignup['signup_last_name']  ?? '')) !== '' ? (string) $slotSignup['signup_last_name']  : (string) ($slotSignup['last_name']  ?? ''));
        $phone     = trim(((string) ($slotSignup['signup_phone']      ?? '')) !== '' ? (string) $slotSignup['signup_phone']      : (string) ($slotSignup['phone']      ?? ''));

        if ($email === '') {
            return SlotSignupResult::failure('volunteer_insert_failed');
        }

        $db = $this->db ?? db_connect();
        $this->assertSharedConnection($db);

        if (! $db->transBegin()) {
            return SlotSignupResult::failure('transaction_failed');
        }

        try {
            // Cancel the source slot-signup first; now REMOVED → invisible to locking reads below.
            if (! $this->slotSignupModel->markCancelledByAdmin($dto->sourceSlotSignupId, $dto->adminUserId)) {
                $db->transRollback();

                return SlotSignupResult::failure('not_found');
            }

            $result = $this->signupWithinTransaction($db, $dto->targetSlotId, $dto->kermesseId, $email, [
                'first_name' => $firstName,
                'last_name'  => $lastName,
                'phone'      => $phone,
            ], $dto->adminUserId);

            if (! $result->success) {
                $db->transRollback();

                return $result;
            }

            $this->slotSignupModel->stampAdminModification((int) $result->signupId, $dto->adminUserId);
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'moveSlotSignup transaction aborted: ' . $e->getMessage());

            return SlotSignupResult::failure('transaction_failed');
        }

        if (! $db->transCommit()) {
            $db->transRollback();

            return SlotSignupResult::failure('transaction_failed');
        }

        $slot      = $result->context['slot'] ?? null;
        $emailSent = null;

        if ($dto->sendNotificationEmail && is_array($slot)) {
            $emailSent = $this->sendConfirmationEmailSafely($kermesse, $slot, $email, [
                'first_name' => $firstName,
                'last_name'  => $lastName,
            ]);
        }

        $volunteerName = trim($firstName . ' ' . $lastName) ?: $email;

        return SlotSignupResult::success((int) $result->signupId, $result->volunteerId, $emailSent, [
            'volunteer_name' => $volunteerName,
        ]);
    }

    /**
     * "Pâtisseries (20/06 14h – 16h30)" — safe fallback if dates are unparseable.
     */
    private function formatSlotLabel(string $standName, string $startsAt, string $endsAt): string
    {
        $start = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startsAt);
        $end   = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $endsAt);

        if ($start === false || $end === false) {
            return $standName;
        }

        $fmt = static function (\DateTimeImmutable $dt): string {
            return $dt->format('i') === '00'
                ? $dt->format('H') . 'h'
                : $dt->format('H') . 'h' . $dt->format('i');
        };

        return sprintf('%s (%s %s – %s)', $standName, $start->format('d/m'), $fmt($start), $fmt($end));
    }

    /**
     * Send the admin cancellation notification email. Every failure is absorbed.
     */
    private function sendCancellationEmailSafely(string $email, string $firstName, string $kermesseName, string $slotLabel = ''): bool
    {
        if ($email === '') {
            return false;
        }

        try {
            $delivery = ($this->emailService ?? new EmailService())->sendSignupCancellationEmail(
                $email,
                $firstName,
                $kermesseName,
                $slotLabel,
            );

            return $delivery->sent;
        } catch (\Throwable $e) {
            log_message('error', sprintf(
                'SlotSignupService: cancellation email failed for %s (kermesse: %s): %s',
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
     *
     * @param array<string, mixed> $fields
     */
    private function signupWithinTransaction(
        BaseConnection $db,
        int $slotId,
        int $kermesseId,
        string $email,
        array $fields,
        ?int $createdBy = null,
    ): SlotSignupResult {
        // Lock the slot row so concurrent transactions serialize on the capacity check;
        // the count below is then this transaction's first plain read and its snapshot
        // includes every signup committed before the lock was granted.
        $slot = ($this->slotModel ?? model(SlotModel::class))->findForCapacityCheck($slotId, $db);
        if ($slot === null) {
            return SlotSignupResult::failure('slot_full');
        }

        // The public summary already filters inactive/finished slots, but the service
        // owns the invariant: direct callers and admin-deactivation races must not
        // bypass it.
        if (($slot['status'] ?? null) !== SlotModel::STATUS_ACTIVE
            || (string) $slot['ends_at'] < (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d H:i:s')) {
            return SlotSignupResult::failure('slot_unavailable');
        }

        $activeCount = $this->slotSignupModel->countActiveForSlot($slotId, $db);
        if ($activeCount >= (int) $slot['capacity']) {
            return SlotSignupResult::failure('slot_full');
        }

        $userId = $this->findUserByEmail($db, $email);

        if ($this->slotSignupModel->findActiveByEmailOrUserAndSlot($email, $userId, $slotId, $db) !== null) {
            return SlotSignupResult::failure('duplicate_signup');
        }

        $overlap = $this->slotSignupModel->findOverlappingActiveByEmailOrUser(
            $email,
            $userId,
            (string) $slot['starts_at'],
            (string) $slot['ends_at'],
            $slotId,
            $kermesseId,
            $db,
        );
        if ($overlap !== null) {
            return SlotSignupResult::failure('overlap_conflict', [
                'conflicting_starts_at' => $overlap['starts_at'] ?? null,
                'conflicting_ends_at'   => $overlap['ends_at'] ?? null,
            ]);
        }

        // Story 5.14: created_by determines the initial confirmation state.
        // If the session user creates their own signup → accepted_at set immediately (certified).
        // If a visitor (createdBy = null) or admin creates for someone else → unconfirmed.
        $now = date('Y-m-d H:i:s');
        $row = [
            'slot_id'    => $slotId,
            'user_id'    => $userId,
            'created_by' => $createdBy,
            'email'      => $email,
            'first_name' => (string) ($fields['first_name'] ?? ''),
            'last_name'  => (string) ($fields['last_name'] ?? ''),
            'phone'      => (string) ($fields['phone'] ?? ''),
        ];
        if ($createdBy !== null && $userId !== null && $createdBy === $userId) {
            $row['accepted_at'] = $now;
        }

        $signupId = $this->slotSignupModel->skipValidation(true)->insert($row);

        if ($signupId === false) {
            return SlotSignupResult::failure('signup_insert_failed');
        }

        if ($userId !== null) {
            // Assign benevole role so the volunteer can access the dashboard (Story 4.1).
            // INSERT IGNORE: preserves any higher role (owner/admin/gestionnaire) if the
            // user was already a member of this kermesse before signing up as volunteer.
            $db->table('kermesse_user_roles')->ignore(true)->insert([
                'kermesse_id' => $kermesseId,
                'user_id'     => $userId,
                'role'        => UserRoleModel::ROLE_BENEVOLE,
            ]);
        }

        // Internal result: the slot row rides in context so the caller can build
        // the confirmation email after commit; signup() rebuilds the public result.
        return new SlotSignupResult(true, (int) $signupId, $userId, null, ['slot' => $slot]);
    }

    /**
     * Send the confirmation email for a committed slot-signup. Every failure mode —
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
                log_message('error', 'SlotSignupService: magic link generation failed: ' . $e->getMessage());
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
            log_message('error', 'SlotSignupService: confirmation email failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Find the global user by normalized email.
     */
    private function findUserByEmail(BaseConnection $db, string $email): ?int
    {
        $emailHash = $this->userModel->hashEmail($email);
        $existing = $this->userModel->findByEmailHash($emailHash, $db, true);

        return $existing !== null ? (int) $existing['id'] : null;
    }

    /**
     * Accept a slot-signup on behalf of the volunteer — Story 5.14 AC3.
     *
     * Sets accepted_at, which transitions the signup to "certified". Scoped to the
     * volunteer's own signups (by user_id or email) within the kermesse. Only applies
     * when the signup is currently unconfirmed (accepted_at IS NULL).
     *
     * Failure codes: not_found | accept_failed
     */
    public function acceptSlotSignup(int $slotSignupId, int $userId, int $kermesseId): SlotSignupResult
    {
        // Ownership + scope guard: the slot-signup must be an active one in this kermesse.
        $slotSignup = $this->slotSignupModel->findActiveOwnedInKermesse($slotSignupId, $userId, $kermesseId);
        if ($slotSignup === null) {
            return SlotSignupResult::failure('not_found');
        }

        if (! $this->slotSignupModel->markAccepted($slotSignupId, $userId)) {
            return SlotSignupResult::failure('accept_failed');
        }

        return SlotSignupResult::success($slotSignupId, $userId);
    }

    /**
     * Reject a slot-signup on behalf of the volunteer — Story 5.14 AC4.
     *
     * Sets rejected_at, which transitions the signup to "refused" and frees the slot
     * capacity immediately (rejected_at IS NOT NULL → excluded from active count).
     * Scoped to the volunteer's own signups within the kermesse.
     *
     * No kermesse lifecycle check: a volunteer can refuse an invitation even when
     * the kermesse is closed (the action is defensive, not a new booking).
     *
     * Failure codes: not_found | reject_failed
     */
    public function rejectSlotSignup(int $slotSignupId, int $userId, int $kermesseId): SlotSignupResult
    {
        $slotSignup = $this->slotSignupModel->findActiveOwnedInKermesse($slotSignupId, $userId, $kermesseId);
        if ($slotSignup === null) {
            // Idempotent: if the slot-signup was already rejected (double-click / two tabs),
            // the slot is already freed — return success instead of a confusing error.
            if ($this->slotSignupModel->findRejectedOwnedInKermesse($slotSignupId, $userId, $kermesseId) !== null) {
                return SlotSignupResult::success($slotSignupId, $userId);
            }
            return SlotSignupResult::failure('not_found');
        }

        if (! $this->slotSignupModel->markRejected($slotSignupId, $userId)) {
            return SlotSignupResult::failure('reject_failed');
        }

        return SlotSignupResult::success($slotSignupId, $userId);
    }

    /**
     * Resolves orphan slot-signups for a newly logged in user.
     * Maps any unassigned signups created by guests with this email to the user,
     * records that the user has viewed them, inserts the benevole role for each
     * kermesse where orphan signups were attached, and backfills the user profile
     * from signup snapshot data if the profile is still empty.
     */
    /**
     * Stamp accepted_at on all unconfirmed signups for this user created before this login.
     * Called after a successful magic-link validation, once last_login_at is already fresh.
     * Idempotent: WHERE accepted_at IS NULL ensures no-op on already-confirmed signups.
     */
    public function autoAcceptUnconfirmedAfterLogin(int $userId): void
    {
        $db = $this->db ?? db_connect();
        $ss = $db->prefixTable('slot_signups');
        $db->query(
            "UPDATE {$ss}
             SET    accepted_at = NOW()
             WHERE  user_id     = ?
               AND  accepted_at IS NULL
               AND  (created_by IS NULL OR created_by != ?)
               AND  canceled_at IS NULL
               AND  rejected_at IS NULL
               AND  deleted_at  IS NULL",
            [$userId, $userId]
        );
    }

    public function resolveOrphanSlotSignups(string $email, int $userId): int
    {
        $attached = $this->slotSignupModel->attachOrphansToUser($email, $userId);
        // Also stamp viewed_at for non-orphan unconfirmed slot-signups already linked to this user
        $this->slotSignupModel->stampViewedForUnconfirmedSignups($userId);

        if ($attached > 0) {
            // Ensure the user has a benevole role for every kermesse they signed up to as guest.
            // (The role INSERT is normally done in signupWithinTransaction, but was skipped when
            // user_id was NULL at signup time.)
            $db = $this->db ?? db_connect();
            foreach ($this->slotSignupModel->findKermesseIdsForUser($userId) as $kermesseId) {
                $db->table('kermesse_user_roles')->ignore(true)->insert([
                    'kermesse_id' => (int) $kermesseId,
                    'user_id'     => $userId,
                    'role'        => \App\Models\UserRoleModel::ROLE_BENEVOLE,
                ]);
            }

            // Backfill first_name/last_name/phone into users from the slot-signup snapshot
            // when the profile was never filled (newly created accounts via magic link).
            $userRow = $this->userModel->find($userId);
            if ($userRow !== null && (string) ($userRow['first_name'] ?? '') === '') {
                $ss = $this->slotSignupModel->db->prefixTable('slot_signups');
                $snapshot = $this->slotSignupModel->db->query(
                    "SELECT first_name, last_name, phone
                     FROM {$ss}
                     WHERE user_id = ? AND first_name != '' AND deleted_at IS NULL
                     ORDER BY id ASC LIMIT 1",
                    [$userId]
                )->getRowArray();

                if ($snapshot !== null) {
                    $this->userModel->update($userId, [
                        'first_name' => (string) $snapshot['first_name'],
                        'last_name'  => (string) ($snapshot['last_name'] ?? ''),
                        'phone'      => (string) ($snapshot['phone']     ?? ''),
                    ]);
                }
            }
        }

        return $attached;
    }

    /**
     * Locks live on $db; a model bound to a different connection would run its reads
     * and writes outside the transaction and silently void every invariant. Fail fast
     * instead — this is a wiring error, not a runtime condition.
     */
    private function assertSharedConnection(BaseConnection $db): void
    {
        foreach ([$this->userModel, $this->slotSignupModel] as $model) {
            $modelDb = $model->db ?? null;
            if ($modelDb instanceof BaseConnection && $modelDb !== $db) {
                throw new DatabaseException(
                    'SlotSignupService models must share the transaction connection.'
                );
            }
        }
    }
}
