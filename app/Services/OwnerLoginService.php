<?php

namespace App\Services;

use App\Models\KermesseModel;
use App\Models\OwnerModel;

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

        // owner_pending: revoke stale tokens, issue a new one, send email
        $ownerId  = (int) $owner['id'];

        // Resolve kermesse via injected model (testable without live DB)
        $kermesse   = $this->kermesseModel->where('owner_id', $ownerId)->first();
        if ($kermesse === null) {
            log_message('error', 'OwnerLoginService: pending owner has no kermesse row: ' . $ownerId);

            return new LoginRequestResult(LoginRequestResult::CHECK_EMAIL);
        }

        if ($this->emailService->hasRecentSuccessfulOwnerValidationEmail($email, self::RESEND_COOLDOWN_SECONDS)) {
            return new LoginRequestResult(LoginRequestResult::CHECK_EMAIL);
        }

        $kermesseId   = (int) $kermesse['id'];
        $kermesseName = (string) $kermesse['name'];

        try {
            // Issue and send a fresh validation token
            $issuedToken = $this->tokenService->issueOwnerValidationToken($ownerId, $kermesseId, $email);
        } catch (\Throwable $e) {
            log_message('error', 'OwnerLoginService: failed to issue validation token: ' . $e->getMessage());

            return new LoginRequestResult(LoginRequestResult::CHECK_EMAIL);
        }

        $baseUrl       = rtrim(config('Kermesse')->publicBaseURL, '/');
        $validationUrl = $baseUrl . '/owner/validate/' . $issuedToken->rawToken;
        $newTokenId    = $issuedToken->tokenId;

        // rawToken is used only here to build the URL; it is not persisted or returned
        unset($issuedToken);

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

        $this->tokenService->revokeOlderActiveOwnerValidationTokens($ownerId, $newTokenId);

        return new LoginRequestResult(LoginRequestResult::CHECK_EMAIL);
    }
}
