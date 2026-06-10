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
     *
     * Story 3.5 (écart acté 2026-06-10): no management link in this email — the
     * identity model decision (management link vs Magic Link) is pending.
     */
    public function sendSignupConfirmationEmail(
        string $recipientEmail,
        string $firstName,
        string $kermesseName,
        string $standName,
        string $slotStartsAt,
        string $slotEndsAt,
    ): EmailDeliveryResult {
        return $this->deliver(
            recipientEmail: $recipientEmail,
            subject: 'Votre inscription à « ' . $this->safeSubjectPart($kermesseName) . ' » est confirmée',
            viewPath: 'emails/signup_confirmation',
            viewData: [
                'firstName'    => $firstName,
                'kermesseName' => $kermesseName,
                'standName'    => $standName,
                'slotStartsAt' => $slotStartsAt,
                'slotEndsAt'   => $slotEndsAt,
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

            $sent = $email->send(false);

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
