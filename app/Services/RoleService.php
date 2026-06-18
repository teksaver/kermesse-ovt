<?php

namespace App\Services;

use App\Models\KermesseModel;
use App\Models\SignupModel;
use App\Models\UserModel;
use App\Models\UserRoleModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\I18n\Time;

/**
 * Per-kermesse role authorization and assignment.
 * Owns: role checks (Owner/Admin/Gestionnaire/Bénévole), role assignment, invitations.
 * Implemented in Stories 2.x and 4.5.
 */
class RoleService
{
    private TokenService $tokenService;
    private EmailService $emailService;
    private KermesseModel $kermesseModel;

    /**
     * Invitable roles (Story 4.5, AC1): an invitation can only grant Admin or
     * Gestionnaire. `owner` is reserved to the creator and `benevole` is acquired
     * by signing up, never by invitation.
     */
    private const INVITABLE_ROLES = [UserRoleModel::ROLE_ADMIN, UserRoleModel::ROLE_GESTIONNAIRE];

    /**
     * TokenService / EmailService / KermesseModel are optional so the role-check
     * call sites (RoleFilter, KermesseAdminController::show) keep working with the
     * historical two-argument constructor; only invite() needs the extra collaborators.
     */
    public function __construct(
        private readonly UserRoleModel $userRoleModel,
        private readonly UserModel $userModel,
        ?TokenService $tokenService = null,
        ?EmailService $emailService = null,
        ?KermesseModel $kermesseModel = null,
    ) {
        $this->tokenService  = $tokenService ?? new TokenService();
        $this->emailService  = $emailService ?? new EmailService();
        $this->kermesseModel = $kermesseModel ?? model(KermesseModel::class);
    }

    /**
     * Record dashboard access for (kermesse, user): sets first_access_at when NULL,
     * always updates last_access_at. Called on every KermesseAdminController::show load.
     */
    public function recordAccess(int $kermesseId, int $userId): void
    {
        $row = $this->userRoleModel->findByKermesseAndUser($kermesseId, $userId);
        if ($row === null) {
            return;
        }

        $now  = Time::now()->toDateTimeString();
        $data = ['last_access_at' => $now];

        if ($row['first_access_at'] === null) {
            $data['first_access_at'] = $now;
        }

        $this->userRoleModel->update((int) $row['id'], $data);
    }

    /**
     * Return the role string for the given user on the given kermesse, or null.
     */
    public function getRoleForUser(int $kermesseId, int $userId): ?string
    {
        $row = $this->userRoleModel->findByKermesseAndUser($kermesseId, $userId);

        return $row !== null ? (string) $row['role'] : null;
    }

    public function isOwnerOrAdmin(int $kermesseId, int $userId): bool
    {
        $role = $this->getRoleForUser($kermesseId, $userId);

        return in_array($role, [UserRoleModel::ROLE_OWNER, UserRoleModel::ROLE_ADMIN], true);
    }

    public function canManage(int $kermesseId, int $userId): bool
    {
        $role = $this->getRoleForUser($kermesseId, $userId);

        return in_array($role, [
            UserRoleModel::ROLE_OWNER,
            UserRoleModel::ROLE_ADMIN,
            UserRoleModel::ROLE_GESTIONNAIRE,
        ], true);
    }

    /**
     * Invite a person (by email) to manage a kermesse as Admin or Gestionnaire (Story 4.5).
     *
     * Encapsulates the full invitation invariant so no controller mutates roles directly:
     *   1. the global user is created if the email is unknown;
     *   2. the requested role is assigned (or updated) on kermesse_user_roles, tracking invited_by;
     *   3. a single-use Magic Link carrying the kermesse intent is issued via TokenService;
     *   4. an invitation email is sent and traced in email_events via EmailService.
     *
     * Steps 1–3 run in one transaction so an invitee is never left without the intended
     * role. Email delivery happens after commit and never blocks the assignment: a failed
     * send is already traced in email_events for ops follow-up (consistent with Stories 3.5/1.x).
     *
     * @param int    $kermesseId Target kermesse.
     * @param string $email      Invitee email (validated by the caller; normalized here).
     * @param string $role       Requested role; must be Admin or Gestionnaire.
     * @param int    $invitedBy  User id of the Owner/Admin performing the invitation.
     */
    public function invite(int $kermesseId, string $email, string $role, int $invitedBy, string $firstName, string $lastName): InvitationResult
    {
        if (! in_array($role, self::INVITABLE_ROLES, true)) {
            return InvitationResult::failure('invalid_role');
        }

        // Defense in depth: the controller already validates the email, but invite() is the
        // service-owned entry point and must not assign a role to a malformed address.
        $email = strtolower(trim($email));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return InvitationResult::failure('invalid_email');
        }

        $kermesse = $this->kermesseModel->find($kermesseId);
        if ($kermesse === null) {
            return InvitationResult::failure('kermesse_not_found');
        }

        $db = \Config\Database::connect();

        // Manual transaction mode (like SignupService): a duplicate-key race on the unique
        // (kermesse_id, user_id) stays recoverable as an idempotent update instead of dooming
        // the whole transaction, which transStart()'s automatic status tracking would do.
        if (! $db->transBegin()) {
            return InvitationResult::failure('system_error');
        }

        try {
            $userId = $this->userModel->findOrCreateWithProfile($email, $firstName, $lastName);
            if ($userId === null) {
                $db->transRollback();

                return InvitationResult::failure('system_error');
            }

            $existing = $this->userRoleModel->findByKermesseAndUser($kermesseId, $userId);

            // The creator's ownership is never reassigned through the invitation surface
            // (an Owner inviting their own email must not be silently demoted to Admin).
            if ($existing !== null && (string) $existing['role'] === UserRoleModel::ROLE_OWNER) {
                $db->transRollback();

                return InvitationResult::failure('cannot_invite_owner');
            }

            // AC2 (Story 5.5): Prevent duplicate role assignment. If the user already has
            // the exact same role for this kermesse, return already_has_role error instead
            // of silently ignoring the duplicate invitation.
            if ($existing !== null && (string) $existing['role'] === $role) {
                $db->transRollback();

                return InvitationResult::failure('already_has_role');
            }

            $this->assignRole($kermesseId, $userId, $role, $invitedBy, $existing);

            // Magic Link with kermesse intent: after login the invitee lands on the dashboard
            // (MagicLinkController::verify redirects to kermesse/{kermesse_id}).
            $issued = $this->tokenService->issueMagicLink($email, $kermesseId);
        } catch (\Throwable $e) {
            // Any unrecoverable failure (token insert, user creation, non-duplicate DB error)
            // rolls back the whole invitation rather than leaving a partial/open transaction.
            $db->transRollback();
            log_message('error', 'RoleService::invite aborted: ' . $e->getMessage());

            return InvitationResult::failure('system_error');
        }

        if (! $db->transCommit()) {
            $db->transRollback();

            return InvitationResult::failure('system_error');
        }

        // Email is sent after commit so a delivery failure never undoes the role assignment;
        // EmailService already traces the failure in email_events for ops follow-up.
        $delivery = $this->emailService->sendRoleInvitationEmail(
            $email,
            (string) $kermesse['name'],
            $this->roleLabel($role),
            site_url('auth/magic-link/' . $issued->rawToken),
        );

        // Notify the Owner that a new member joined the team (who invited whom, which role).
        // Non-blocking: a delivery failure is traced in email_events but never aborts the result.
        $this->notifyOwnerTeamChange(
            kermesseId: $kermesseId,
            kermesseName: (string) $kermesse['name'],
            memberName: trim($firstName . ' ' . $lastName),
            action: 'joined',
            actorId: $invitedBy,
            roleLabel: $this->roleLabel($role),
        );

        return InvitationResult::success($userId, $role, $delivery->sent);
    }

    /**
     * Re-send an invitation email for a pending (not yet accepted) role (Story 5.5 — relance).
     *
     * Unlike invite(), this method does NOT touch the role row; it only issues a new Magic Link
     * and resends the email. Calling invite() for a resend would fail with already_has_role
     * because the role already exists.
     *
     * @return bool True when the email was dispatched (or traced in email_events), false when
     *              the role does not exist or the invitation is already accepted.
     */
    public function resendInvitation(int $kermesseId, int $userId): bool
    {
        $roleRow = $this->userRoleModel->findByKermesseAndUser($kermesseId, $userId);
        if ($roleRow === null) {
            return false;
        }

        $user = $this->userModel->find($userId);
        if ($user === null) {
            return false;
        }

        // accepted_at is set on the first magic link click, before the user completes profile
        // confirmation. Block resend only when the user has actually completed login (last_login_at
        // is set), not when they merely clicked the link and abandoned the confirmation screen.
        if (($roleRow['accepted_at'] ?? null) !== null && $user['last_login_at'] !== null) {
            return false;
        }

        $kermesse = $this->kermesseModel->find($kermesseId);
        if ($kermesse === null) {
            return false;
        }

        $email    = (string) $user['email'];
        $issued   = $this->tokenService->issueMagicLink($email, $kermesseId);
        $this->emailService->sendRoleInvitationEmail(
            $email,
            (string) $kermesse['name'],
            $this->roleLabel((string) $roleRow['role']),
            site_url('auth/magic-link/' . $issued->rawToken),
        );

        return true;
    }

    /**
     * Insert or update the role row for (kermesse, user), keeping the operation idempotent.
     *
     * If a concurrent invitation wins the insert race on uq_role_per_kermesse, the duplicate-key
     * error is caught and converted to an update so the caller never sees a 500.
     *
     * @param array<string, mixed>|null $existing Role row already read in this transaction, if any.
     */
    private function assignRole(int $kermesseId, int $userId, string $role, int $invitedBy, ?array $existing): void
    {
        $now = date('Y-m-d H:i:s');

        if ($existing !== null) {
            // Re-invitation (Story 5.5, AC3): only role/invited_by/invited_at are refreshed.
            // first_access_at is intentionally NOT in this payload so a former member who already
            // accessed the kermesse keeps their original first_access_at — they reappear as an
            // active member, never back in "En attente". Do not add first_access_at here.
            $this->userRoleModel->update((int) $existing['id'], [
                'role'        => $role,
                'invited_by'  => $invitedBy,
                'invited_at'  => $now,
            ]);

            return;
        }

        try {
            $this->userRoleModel->insert([
                'kermesse_id' => $kermesseId,
                'user_id'     => $userId,
                'role'        => $role,
                'invited_by'  => $invitedBy,
                'invited_at'  => $now,
            ]);
        } catch (DatabaseException $e) {
            if (! $this->isDuplicateKey($e)) {
                throw $e;
            }

            // Concurrent invite already created the row: re-read and update so the
            // requested role still wins, keeping the invitation idempotent.
            $row = $this->userRoleModel->findByKermesseAndUser($kermesseId, $userId);
            if ($row !== null) {
                $this->userRoleModel->update((int) $row['id'], [
                    'role'        => $role,
                    'invited_by'  => $invitedBy,
                    'invited_at'  => $now,
                ]);
            }
        }
    }

    private function isDuplicateKey(DatabaseException $e): bool
    {
        $msg = $e->getMessage();

        return $e->getCode() === 1062
            || $e->getCode() === 23505
            || str_contains($msg, 'Duplicate entry')
            || str_contains($msg, 'UNIQUE constraint failed');
    }

    /**
     * Human-readable French label for a role code (used in invitation copy).
     */
    private function roleLabel(string $role): string
    {
        return match ($role) {
            UserRoleModel::ROLE_ADMIN        => 'administrateur',
            UserRoleModel::ROLE_GESTIONNAIRE => 'gestionnaire',
            default                          => $role,
        };
    }

    /**
     * Revokes the elevated role (Admin/Gestionnaire) for a given user on a given kermesse.
     * Returns false when no operation was performed (Owner target, or row not found).
     *
     * $actorId identifies who performed the action (Owner or Admin) so the Owner notification
     * can name the actor — even when the Owner removes someone themselves.
     *
     * Logic (Story 5.8):
     *   - Pending invite (invited_at IS NOT NULL AND accepted_at IS NULL AND first_access_at IS NULL):
     *       - no active slot signups → row is deleted (invitation cancelled)
     *       - active slot signups → downgraded to bénévole (participations preserved)
     *   - Active member (not pending, or mid-confirmation with accepted_at IS NOT NULL):
     *       - always downgraded to bénévole, regardless of slot signups, so they keep
     *         the kermesse visible in "Mes participations" and can leave via Story 5.9.
     */
    public function removeRole(int $kermesseId, int $userId, int $actorId): bool
    {
        $existing = $this->userRoleModel->findByKermesseAndUser($kermesseId, $userId);
        if ($existing === null || (string) $existing['role'] === UserRoleModel::ROLE_OWNER) {
            return false;
        }

        $removedRoleLabel = $this->roleLabel((string) $existing['role']);

        $member = $this->userModel->find($userId);
        $memberName = $member !== null
            ? trim((string) $member['first_name'] . ' ' . (string) $member['last_name'])
            : '';

        // accepted_at is set when the invitee clicks the magic link, before first_access_at is
        // recorded on dashboard load. A mid-confirmation user (accepted_at IS NOT NULL,
        // first_access_at IS NULL) must NOT have their row deleted — they are mid-flow.
        $isPendingInvite = $existing['invited_at'] !== null
            && ($existing['accepted_at'] ?? null) === null
            && $existing['first_access_at'] === null;

        if ($isPendingInvite && ! $this->hasActiveSlotSignups($kermesseId, $userId)) {
            $this->userRoleModel->delete((int) $existing['id']);
        } else {
            $this->userRoleModel->update((int) $existing['id'], ['role' => UserRoleModel::ROLE_BENEVOLE]);
        }

        // Notify the Owner that a member was removed (who did it, whom, which role).
        $kermesse = $this->kermesseModel->find($kermesseId);
        if ($kermesse !== null && $memberName !== '') {
            $this->notifyOwnerTeamChange(
                kermesseId: $kermesseId,
                kermesseName: (string) $kermesse['name'],
                memberName: $memberName,
                action: 'removed',
                actorId: $actorId,
                roleLabel: $removedRoleLabel,
            );
        }

        return true;
    }

    /**
     * Returns true if the user has at least one active slot signup on the given kermesse.
     * Used by removeRole() to decide between invitation cancellation and bénévole downgrade.
     *
     * "Active" MUST use the same definition as SignupModel (status NOT IN INACTIVE_STATUSES
     * AND deleted_at IS NULL) so the revocation decision can never diverge from the
     * "Gestion des inscrits" / public availability views (CLAUDE.md single-definition rule).
     * Filtering on signup status alone is sufficient: removing a slot/stand cascades to the
     * signup status, so a non-inactive signup can never point at a removed slot/stand.
     */
    private function hasActiveSlotSignups(int $kermesseId, int $userId): bool
    {
        $db = db_connect();

        return $db
            ->table('signups')
            ->join('slots', 'slots.id = signups.slot_id')
            ->join('stands', 'stands.id = slots.stand_id')
            ->where('stands.kermesse_id', $kermesseId)
            ->where('signups.user_id', $userId)
            ->where('signups.canceled_at', null)
            ->where('signups.rejected_at', null)
            ->where($db->DBPrefix . 'signups.deleted_at IS NULL', null, false)
            ->countAllResults() > 0;
    }

    /**
     * Allow any non-Owner member to leave a kermesse entirely (Story 5.9).
     *
     * Invariants (in order):
     *   1. Non-member (row absent)  → failure('unauthorized_role') — idempotent; the
     *      route filter already blocks probing by non-members, but we must not throw.
     *   2. Owner                    → failure('unauthorized_role').
     *   3. Active slot signups      → failure('has_active_signups'), row preserved.
     *   4. Otherwise                → DELETE the kermesse_user_roles row, success().
     *
     * The Owner is notified after the DELETE so they always know when a member leaves
     * voluntarily (actor = member themselves).
     *
     * Stable error codes: `unauthorized_role` (pre-existing) and `has_active_signups` (new).
     */
    public function leaveKermesse(int $kermesseId, int $userId): LeaveKermesseResult
    {
        $existing = $this->userRoleModel->findByKermesseAndUser($kermesseId, $userId);

        if ($existing === null || (string) $existing['role'] === UserRoleModel::ROLE_OWNER) {
            return LeaveKermesseResult::failure('unauthorized_role');
        }

        if ($this->hasActiveSlotSignups($kermesseId, $userId)) {
            return LeaveKermesseResult::failure('has_active_signups');
        }

        $leavingRoleLabel = $this->roleLabel((string) $existing['role']);

        $leavingUser = $this->userModel->find($userId);
        $memberName  = $leavingUser !== null
            ? trim((string) $leavingUser['first_name'] . ' ' . (string) $leavingUser['last_name'])
            : '';

        $this->userRoleModel->delete((int) $existing['id']);

        // Notify the Owner that a member left voluntarily (actor = member themselves).
        $kermesse = $this->kermesseModel->find($kermesseId);
        if ($kermesse !== null && $memberName !== '') {
            $this->notifyOwnerTeamChange(
                kermesseId: $kermesseId,
                kermesseName: (string) $kermesse['name'],
                memberName: $memberName,
                action: 'left',
                actorId: $userId,
                roleLabel: $leavingRoleLabel,
            );
        }

        return LeaveKermesseResult::success();
    }

    /**
     * Returns true when the user is eligible to leave the kermesse:
     * not Owner AND no active slot signups.
     *
     * Used by controllers to pre-compute the "canLeave" View Model boolean without
     * exposing hasActiveSlotSignups() beyond this class.
     */
    public function canLeaveKermesse(int $kermesseId, int $userId): bool
    {
        $role = $this->getRoleForUser($kermesseId, $userId);

        if ($role === null || $role === UserRoleModel::ROLE_OWNER) {
            return false;
        }

        return ! $this->hasActiveSlotSignups($kermesseId, $userId);
    }

    /**
     * Fetch team members (Owner/Admin/Gestionnaire) grouped by status and role.
     * Story 5.5 — Onglet « Équipe ».
     *
     * Returns an array with keys:
     *   - 'active': associative array grouped by role (owner, admin, gestionnaire)
     *   - 'pending': flat list of pending invitations (invited_at IS NOT NULL, first_access_at IS NULL)
     *
     * Pending: invited_at IS NOT NULL AND first_access_at IS NULL (invitation sent, never accessed).
     * Active: every other team member — first_access_at IS NOT NULL OR invited_at IS NULL. The
     * second branch matters for the Owner, who is never "invited" (invited_at stays NULL) and must
     * still appear even before their first dashboard load; treating active as "not pending" keeps
     * such rows from silently vanishing from the team view.
     */
    public function getTeamMembersGroupedByStatus(int $kermesseId): array
    {
        $allMembers = $this->userRoleModel->findTeamMembers($kermesseId);

        $active  = ['owner' => [], 'admin' => [], 'gestionnaire' => []];
        $pending = [];

        foreach ($allMembers as $member) {
            $isPending = $member['first_access_at'] === null && $member['invited_at'] !== null;

            if ($isPending) {
                $pending[] = $member;

                continue;
            }

            // Active = not pending. findTeamMembers only returns owner/admin/gestionnaire, but a
            // fallback bucket guarantees an unexpected role is still surfaced rather than dropped.
            $role = (string) $member['role'];
            if (! isset($active[$role])) {
                $active[$role] = [];
            }
            $active[$role][] = $member;
        }

        return [
            'active'  => $active,
            'pending' => $pending,
        ];
    }

    /**
     * Public entry point for controllers that need to notify the Owner of a role change.
     * Called by KermesseAdminController::updateTeamMember() after a successful role update.
     */
    public function notifyRoleChanged(
        int $kermesseId,
        string $kermesseName,
        string $memberName,
        int $actorId,
        string $newRoleLabel,
        string $oldRoleLabel,
    ): void {
        $this->notifyOwnerTeamChange(
            kermesseId:   $kermesseId,
            kermesseName: $kermesseName,
            memberName:   $memberName,
            action:       'role_changed',
            actorId:      $actorId,
            roleLabel:    $newRoleLabel,
            oldRoleLabel: $oldRoleLabel,
        );
    }

    /**
     * Send a team-change notification to the kermesse Owner.
     *
     * Resolves actor name from UserModel so callers only pass an int (no PII leakage
     * through parameter chains). Non-blocking: a send or trace failure is logged but
     * never propagated — team mutations must not be rolled back because of an email.
     *
     * @param string $action    One of: joined | left | removed | role_changed
     * @param string $oldRoleLabel Only for role_changed; empty string otherwise
     */
    private function notifyOwnerTeamChange(
        int $kermesseId,
        string $kermesseName,
        string $memberName,
        string $action,
        int $actorId,
        string $roleLabel,
        string $oldRoleLabel = '',
    ): void {
        $owner = $this->userRoleModel->findOwner($kermesseId);
        if ($owner === null) {
            return;
        }

        $actor     = $this->userModel->find($actorId);
        $actorName = $actor !== null
            ? trim((string) $actor['first_name'] . ' ' . (string) $actor['last_name'])
            : 'un administrateur';

        if ($actorName === '') {
            $actorName = 'un administrateur';
        }

        $this->emailService->sendTeamChangeNotificationEmail(
            ownerEmail:     (string) $owner['email'],
            ownerFirstName: (string) $owner['first_name'],
            kermesseName:   $kermesseName,
            memberName:     $memberName,
            action:         $action,
            actorName:      $actorName,
            roleLabel:      $roleLabel,
            oldRoleLabel:   $oldRoleLabel,
        );
    }
}
