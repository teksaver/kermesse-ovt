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
        $recipientHash = hash('sha256', strtolower(trim($recipientEmail)));

        $sent         = false;
        $errorMessage = null;

        $obLevel = ob_get_level();
        try {
            $emailBody = view('emails/owner_validation', [
                'ownerName'     => $ownerName,
                'kermesseName'  => $kermesseName,
                'validationUrl' => $validationUrl,
            ]);
            $safeKermesseName = str_replace(["\r", "\n"], ' ', $kermesseName);

            $email = \Config\Services::email();
            $email->setTo($recipientEmail);
            $email->setSubject("Validez votre kermesse « {$safeKermesseName} »");
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
                    'event_type'      => 'owner_validation',
                    'status'          => $sent ? 'sent' : 'failed',
                    'recipient_email' => $recipientEmail,
                    'recipient_hash'  => $recipientHash,
                    'error_message'   => $errorMessage,
                    'metadata'        => json_encode([
                        'kermesse_name' => $kermesseName,
                        'owner_name'    => $ownerName,
                    ]),
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
     * Send the owner login email and record an email_event.
     */
    public function sendOwnerLoginEmail(
        string $recipientEmail,
        string $ownerName,
        string $kermesseName,
        string $loginUrl,
    ): EmailDeliveryResult {
        $recipientHash = hash('sha256', strtolower(trim($recipientEmail)));

        $sent         = false;
        $errorMessage = null;

        $obLevel = ob_get_level();
        try {
            $emailBody = view('emails/owner_login', [
                'ownerName'    => $ownerName,
                'kermesseName' => $kermesseName,
                'loginUrl'     => $loginUrl,
                'ttlMinutes'   => (int) round(config('Kermesse')->ownerLoginTokenTTL / 60),
            ]);
            $safeKermesseName = str_replace(["\r", "\n"], ' ', $kermesseName);

            $email = \Config\Services::email();
            $email->setTo($recipientEmail);
            $email->setSubject("Connectez-vous à votre kermesse « {$safeKermesseName} »");
            $email->setMessage($emailBody);
            $email->setMailType('html');

            $sent = $email->send(false);

            if (! $sent) {
                $errorMessage = 'Email send returned false';
            }
        } catch (\Throwable $e) {
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
            $errorMessage = 'Email delivery failure';
            log_message('error', 'EmailService: ' . $e->getMessage());
        }

        try {
            try {
                $this->emailEventModel->skipValidation(true);
                $this->emailEventModel->insert([
                    'event_type'      => 'owner_login',
                    'status'          => $sent ? 'sent' : 'failed',
                    'recipient_email' => $recipientEmail,
                    'recipient_hash'  => $recipientHash,
                    'error_message'   => $errorMessage,
                    'metadata'        => json_encode([
                        'kermesse_name' => $kermesseName,
                        'owner_name'    => $ownerName,
                    ]),
                ]);
            } finally {
                $this->emailEventModel->skipValidation(false);
            }
        } catch (\Throwable $e) {
            log_message('error', 'EmailService: failed to record email_event: ' . $e->getMessage());
        }

        return new EmailDeliveryResult($sent, $errorMessage);
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
}
