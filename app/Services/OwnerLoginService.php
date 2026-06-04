<?php

namespace App\Services;

use App\Models\KermesseModel;
use App\Models\OwnerModel;
use CodeIgniter\Database\BaseConnection;

/**
 * Orchestrates the "me connecter" flow for owners whose validation link has expired.
 *
 * This covers AC 3 and 4 of Story 1.5.
 * The login flow for already-validated owners (Story 1.6) is intentionally out of scope.
 */
class OwnerLoginService
{
    private const RESEND_COOLDOWN_SECONDS = 300;

    private OwnerModel    $ownerModel;
    private KermesseModel $kermesseModel;
    private TokenService  $tokenService;
    private EmailService  $emailService;

    public function __construct(
        ?OwnerModel    $ownerModel    = null,
        ?TokenService  $tokenService  = null,
        ?EmailService  $emailService  = null,
        ?KermesseModel $kermesseModel = null,
    ) {
        $this->ownerModel    = $ownerModel    ?? model(OwnerModel::class);
        $this->kermesseModel = $kermesseModel ?? model(KermesseModel::class);
        $this->tokenService  = $tokenService  ?? new TokenService();
        $this->emailService  = $emailService  ?? new EmailService();
    }

    /**
     * Handle a resend-link request.
     *
     * Security invariants:
     * - The response is always the same neutral message regardless of whether the
     *   email is unknown, the owner is already active, or the email was successfully
     *   sent. This prevents user enumeration.
     * - A new token is issued ONLY for owner_pending owners; active owners are
     *   handled by Story 1.6 (not this story).
     */
    public function requestOwnerLink(string $rawEmail): LoginRequestResult
    {
        $email     = strtolower(trim($rawEmail));
        $emailHash = hash('sha256', $email);

        $owner = $this->ownerModel
            ->where('email_hash', $emailHash)
            ->first();

        // Unknown email or already-active owner: return neutral result without action
        if ($owner === null || $owner['status'] === 'active') {
            // NOTE: active owners will get a real passwordless login link in Story 1.6.
            return new LoginRequestResult(LoginRequestResult::CHECK_EMAIL);
        }

        if ($owner['status'] !== 'owner_pending') {
            return new LoginRequestResult(LoginRequestResult::CHECK_EMAIL);
        }

        $ownerId  = (int) $owner['id'];

        $kermesse = $this->kermesseModel->where('owner_id', $ownerId)->first();
        if ($kermesse === null) {
            log_message('error', 'OwnerLoginService: pending owner has no kermesse row: ' . $ownerId);

            return new LoginRequestResult(LoginRequestResult::CHECK_EMAIL);
        }

        // Cooldown based on an active token in the DB, not on email_events.
        // This prevents issuing a new token when a recent, usable one already exists.
        if ($this->tokenService->hasRecentActiveOwnerValidationToken($ownerId, self::RESEND_COOLDOWN_SECONDS)) {
            return new LoginRequestResult(LoginRequestResult::CHECK_EMAIL);
        }

        $kermesseId   = (int) $kermesse['id'];
        $kermesseName = (string) $kermesse['name'];

        // Atomically revoke all active tokens then issue a new one in a single transaction.
        // This ensures: (a) a delivered email always carries a valid token, and (b) at
        // most one exploitable token exists at any moment for this owner.
        /** @var BaseConnection $db */
        $db = \Config\Database::connect();
        $db->transException(true);

        $rawToken   = null;
        $newTokenId = null;

        try {
            $db->transBegin();

            $this->tokenService->revokeActiveOwnerValidationTokens($ownerId);
            $issuedToken = $this->tokenService->issueOwnerValidationToken($ownerId, $kermesseId, $email);
            $rawToken    = $issuedToken->rawToken;
            $newTokenId  = $issuedToken->tokenId;
            unset($issuedToken);

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'OwnerLoginService: failed to issue validation token: ' . $e->getMessage());

            return new LoginRequestResult(LoginRequestResult::CHECK_EMAIL);
        }

        $baseUrl       = rtrim(config('Kermesse')->publicBaseURL, '/');
        $validationUrl = $baseUrl . '/owner/validate/' . $rawToken;
        $rawToken      = null; // used only for URL construction

        $emailResult = $this->emailService->sendOwnerValidationEmail(
            $email,
            $owner['display_name'],
            $kermesseName,
            $validationUrl,
        );

        if (! $emailResult->sent) {
            $this->tokenService->revokeToken($newTokenId);

            return new LoginRequestResult(LoginRequestResult::CHECK_EMAIL);
        }

        return new LoginRequestResult(LoginRequestResult::CHECK_EMAIL);
    }
}
