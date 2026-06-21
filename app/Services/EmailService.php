<?php

namespace App\Services;

use App\Models\EmailEventModel;

class EmailService
{
    private EmailEventModel $emailEventModel;

    public function __construct(?EmailEventModel $emailEventModel = null)
    {
        $this->emailEventModel = $emailEventModel ?? model(EmailEventModel::class);
    }

    /**
     * Send the owner validation email and record an email_event.
     */
    public function sendOwnerValidationEmail(
        string $recipientEmail,
        string $ownerName,
        string $kermesseName,
        string $validationUrl,
    ): EmailDeliveryResult {
        return $this->deliver(
            recipientEmail: $recipientEmail,
            subject: 'Validez votre kermesse « ' . $this->safeSubjectPart($kermesseName) . ' »',
            viewPath: 'emails/owner_validation',
            viewData: [
                'ownerName'     => $ownerName,
                'kermesseName'  => $kermesseName,
                'validationUrl' => $validationUrl,
            ],
            eventType: 'owner_validation',
            metadata: [
                'kermesse_name' => $kermesseName,
                'owner_name'    => $ownerName,
            ],
        );
    }

    /**
     * Send the owner login email and record an email_event.
     */
    public function sendOwnerLoginEmail(
        string $recipientEmail,
        string $ownerName,
        string $kermesseName,
        string $loginUrl,
    ): EmailDeliveryResult {
        return $this->deliver(
            recipientEmail: $recipientEmail,
            subject: 'Connectez-vous à votre kermesse « ' . $this->safeSubjectPart($kermesseName) . ' »',
            viewPath: 'emails/owner_login',
            viewData: [
                'ownerName'    => $ownerName,
                'kermesseName' => $kermesseName,
                'loginUrl'     => $loginUrl,
                'ttlMinutes'   => (int) round(config('Kermesse')->ownerLoginTokenTTL / 60),
            ],
            eventType: 'owner_login',
            metadata: [
                'kermesse_name' => $kermesseName,
                'owner_name'    => $ownerName,
            ],
        );
    }

    /**
     * Send the volunteer signup confirmation email and record an email_event.
     * When $magicLinkUrl is provided, the email includes a button to access the dashboard.
     */
    public function sendSignupConfirmationEmail(
        string $recipientEmail,
        string $firstName,
        string $kermesseName,
        string $standName,
        string $slotStartsAt,
        string $slotEndsAt,
        string $magicLinkUrl = '',
    ): EmailDeliveryResult {
        return $this->deliver(
            recipientEmail: $recipientEmail,
            subject: 'Votre inscription à « ' . $this->safeSubjectPart($kermesseName) . ' » est confirmée',
            viewPath: 'emails/slot_signup_confirmation',
            viewData: [
                'firstName'    => $firstName,
                'kermesseName' => $kermesseName,
                'standName'    => $standName,
                'slotStartsAt' => $slotStartsAt,
                'slotEndsAt'   => $slotEndsAt,
                'magicLinkUrl' => $magicLinkUrl,
                'ttlMinutes'   => max(1, (int) round((config('Kermesse')->magicLinkTokenTTL ?? 900) / 60)),
            ],
            eventType: 'signup_confirmation',
            metadata: [
                'kermesse_name'  => $kermesseName,
                'stand_name'     => $standName,
                'slot_starts_at' => $slotStartsAt,
                'slot_ends_at'   => $slotEndsAt,
            ],
        );
    }

    /**
     * Send the universal Magic Link login email and record an email_event.
     */
    public function sendUserLoginEmail(
        string $recipientEmail,
        string $loginUrl,
    ): EmailDeliveryResult {
        return $this->deliver(
            recipientEmail: $recipientEmail,
            subject: 'Votre lien de connexion',
            viewPath: 'emails/magic_link',
            viewData: [
                'loginUrl' => $loginUrl,
                // max(1, …) : éviter d'afficher « 0 minute » si un TTL < 30 s est configuré.
                'ttlMinutes' => max(1, (int) round(config('Kermesse')->magicLinkTokenTTL / 60)),
            ],
            eventType: 'magic_link',
            metadata: [],
        );
    }

    /**
     * Send a role invitation email (Story 4.5) and record an email_event.
     *
     * The link is a universal Magic Link carrying the kermesse intent; the raw token URL
     * is intentionally kept out of $metadata so it never lands in the email_events trace.
     */
    public function sendRoleInvitationEmail(
        string $recipientEmail,
        string $kermesseName,
        string $roleLabel,
        string $invitationUrl,
    ): EmailDeliveryResult {
        return $this->deliver(
            recipientEmail: $recipientEmail,
            subject: 'Invitation à gérer la kermesse « ' . $this->safeSubjectPart($kermesseName) . ' »',
            viewPath: 'emails/role_invitation',
            viewData: [
                'kermesseName'  => $kermesseName,
                'roleLabel'     => $roleLabel,
                'invitationUrl' => $invitationUrl,
                'ttlMinutes'    => max(1, (int) round((config('Kermesse')->magicLinkTokenTTL ?? 900) / 60)),
            ],
            eventType: 'role_invitation',
            metadata: [
                'kermesse_name' => $kermesseName,
                'role'          => $roleLabel,
            ],
        );
    }

    /**
     * Notify a volunteer that an admin has cancelled their signup — Story 5.10 AC1.
     */
    public function sendSignupCancellationEmail(
        string $recipientEmail,
        string $firstName,
        string $kermesseName,
        string $slotLabel = '',
    ): EmailDeliveryResult {
        return $this->deliver(
            recipientEmail: $recipientEmail,
            subject: 'Votre inscription à « ' . $this->safeSubjectPart($kermesseName) . ' » a été annulée',
            viewPath: 'emails/slot_signup_cancellation',
            viewData: [
                'firstName'    => $firstName,
                'kermesseName' => $kermesseName,
                'slotLabel'    => $slotLabel,
            ],
            eventType: 'signup_cancellation',
            metadata: [
                'kermesse_name' => $kermesseName,
            ],
        );
    }

    /**
     * Notify the Owner of a kermesse of any team membership change.
     *
     * Covers four actions triggered by RoleService and KermesseAdminController:
     *   - 'joined'       : a new Admin/Gestionnaire accepted their invitation
     *   - 'left'         : a member left voluntarily (Story 5.9)
     *   - 'removed'      : a member was revoked by an Admin/Owner
     *   - 'role_changed' : an existing member's role was updated
     *
     * The notification is sent even when the actor is the Owner themselves — this
     * guarantees every team change produces both a confirmation and an email_events trace.
     *
     * @param string $action       One of: joined | left | removed | role_changed
     * @param string $oldRoleLabel Only meaningful for role_changed; empty otherwise
     */
    public function sendTeamChangeNotificationEmail(
        string $ownerEmail,
        string $ownerFirstName,
        string $kermesseName,
        string $memberName,
        string $action,
        string $actorName,
        string $roleLabel,
        string $oldRoleLabel = '',
    ): EmailDeliveryResult {
        return $this->deliver(
            recipientEmail: $ownerEmail,
            subject: 'Changement dans l\'équipe de « ' . $this->safeSubjectPart($kermesseName) . ' »',
            viewPath: 'emails/team_change_notification',
            viewData: [
                'ownerFirstName' => $ownerFirstName,
                'kermesseName'   => $kermesseName,
                'memberName'     => $memberName,
                'action'         => $action,
                'actorName'      => $actorName,
                'roleLabel'      => $roleLabel,
                'oldRoleLabel'   => $oldRoleLabel,
            ],
            eventType: 'team_change_notification',
            metadata: [
                'action'         => $action,
                'member_name'    => $memberName,
                'actor_name'     => $actorName,
                'role'           => $roleLabel,
                'old_role'       => $oldRoleLabel,
                'kermesse_name'  => $kermesseName,
            ],
        );
    }

    public function hasRecentSuccessfulOwnerValidationEmail(string $recipientEmail, int $cooldownSeconds): bool
    {
        $recipientHash = hash('sha256', strtolower(trim($recipientEmail)));
        $createdAfter  = date('Y-m-d H:i:s', time() - $cooldownSeconds);

        $event = $this->emailEventModel
            ->where('event_type', 'owner_validation')
            ->where('status', 'sent')
            ->where('recipient_hash', $recipientHash)
            ->where('created_at >=', $createdAfter)
            ->first();

        return $event !== null;
    }

    /**
     * Render, send and trace one email. Shared by every public send method so the
     * failure-isolation rules live in exactly one place: a send failure is captured
     * (never thrown to the caller), error messages stay neutral (no SMTP internals,
     * no raw token URLs), and the email_events insert is itself non-blocking.
     *
     * @param array<string, mixed> $viewData
     * @param array<string, mixed> $metadata
     */
    private function deliver(
        string $recipientEmail,
        string $subject,
        string $viewPath,
        array $viewData,
        string $eventType,
        array $metadata,
    ): EmailDeliveryResult {
        $recipientHash = hash('sha256', strtolower(trim($recipientEmail)));

        $sent         = false;
        $errorMessage = null;

        $obLevel = ob_get_level();
        try {
            $emailBody = view($viewPath, $viewData);

            $email = \Config\Services::email();
            $email->setTo($recipientEmail);
            $email->setSubject($subject);
            $email->setMessage($emailBody);
            $email->setMailType('html');

            $sent = $email->send();

            if (! $sent) {
                $errorMessage = 'Email send returned false';
            }
        } catch (\Throwable $e) {
            // Clean up output buffers left open by a failed view render
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
            // Sanitize: do not expose SMTP internals to caller
            $errorMessage = 'Email delivery failure';
            log_message('error', 'EmailService: ' . $e->getMessage());
        }

        try {
            try {
                $this->emailEventModel->skipValidation(true);
                $this->emailEventModel->insert([
                    'event_type'      => $eventType,
                    'status'          => $sent ? 'sent' : 'failed',
                    'recipient_email' => $recipientEmail,
                    'recipient_hash'  => $recipientHash,
                    'error_message'   => $errorMessage,
                    'metadata'        => json_encode($metadata),
                ]);
            } finally {
                $this->emailEventModel->skipValidation(false);
            }
        } catch (\Throwable $e) {
            // Non-blocking: log the failure but do not surface it to the caller
            log_message('error', 'EmailService: failed to record email_event: ' . $e->getMessage());
        }

        return new EmailDeliveryResult($sent, $errorMessage);
    }

    /**
     * Header-injection guard for subject interpolation.
     */
    private function safeSubjectPart(string $value): string
    {
        return str_replace(["\r", "\n"], ' ', $value);
    }
}
