<?php

namespace App\Services;

use App\Models\KermesseModel;
use App\Models\OwnerModel;
use CodeIgniter\Database\BaseConnection;

/**
 * Orchestrates the "me connecter" flow.
 *
 * - owner_pending: re-send the owner_validation link (Story 1.5).
 * - owner active:  issue an owner_login link (Story 1.6).
 * - unknown email: neutral response, no action.
 */
class OwnerLoginService
{
    /** Cooldown for re-sending the owner_validation link (pending owners). */
    private const RESEND_COOLDOWN_SECONDS = 300;

    /** Cooldown for re-sending the owner_login link (active owners). */
    private const LOGIN_RESEND_COOLDOWN_SECONDS = 300;

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
     * Handle a login/resend-link request.
     *
     * Security invariants:
     * - The response is always the same neutral message regardless of whether the
     *   email is unknown, the owner is pending or active, the email was sent, or any
     *   error occurred. This prevents user enumeration.
     */
    public function requestOwnerLink(string $rawEmail): LoginRequestResult
    {
        $email     = strtolower(trim($rawEmail));
        $emailHash = hash('sha256', $email);

        $owner = $this->ownerModel
            ->where('email_hash', $emailHash)
            ->first();

        if ($owner === null) {
            return new LoginRequestResult(LoginRequestResult::CHECK_EMAIL);
        }

        if ($owner['status'] === 'active') {
            return $this->handleActiveOwner($owner, $email);
        }

        if ($owner['status'] !== 'owner_pending') {
            return new LoginRequestResult(LoginRequestResult::CHECK_EMAIL);
        }

        $ownerId = (int) $owner['id'];

        $kermesse = $this->kermesseModel->where('owner_id', $ownerId)->first();
        if ($kermesse === null) {
            log_message('error', 'OwnerLoginService: pending owner has no kermesse row: ' . $ownerId);

            return new LoginRequestResult(LoginRequestResult::CHECK_EMAIL);
        }

        if ($this->tokenService->hasRecentActiveOwnerValidationToken($ownerId, self::RESEND_COOLDOWN_SECONDS)) {
            return new LoginRequestResult(LoginRequestResult::CHECK_EMAIL);
        }

        $kermesseId   = (int) $kermesse['id'];
        $kermesseName = (string) $kermesse['name'];

        // Issue new token first — revoke old links only after email is confirmed delivered.
        // This ensures the user always has a usable link: if email fails, old links remain
        // valid and the new (undelivered) token is revoked so it doesn't block future resends.
        /** @var BaseConnection $db */
        $db = \Config\Database::connect();
        $db->transException(true);

        $rawToken   = null;
        $newTokenId = null;

        try {
            $db->transBegin();

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
        $rawToken      = null;

        $emailResult = $this->emailService->sendOwnerValidationEmail(
            $email,
            $owner['display_name'],
            $kermesseName,
            $validationUrl,
        );

        if ($emailResult->sent) {
            // Email delivered — safe to revoke older links now that user has a working one.
            $this->tokenService->revokeOlderActiveOwnerValidationTokens($ownerId, $newTokenId);
        } else {
            // Email failed — revoke the undelivered token so it doesn't block future resends.
            // Old links, if still active, remain usable.
            $this->tokenService->revokeToken($newTokenId);
        }

        return new LoginRequestResult(LoginRequestResult::CHECK_EMAIL);
    }

    /**
     * Handle the login request for an already-validated (active) owner.
     *
     * Issues an owner_login token and sends a magic link by email.
     * Always returns CHECK_EMAIL regardless of outcome (anti-enumeration).
     */
    private function handleActiveOwner(array $owner, string $email): LoginRequestResult
    {
        $ownerId  = (int) $owner['id'];

        $kermesse = $this->kermesseModel->where('owner_id', $ownerId)->first();
        if ($kermesse === null) {
            log_message('error', 'OwnerLoginService: active owner has no kermesse row: ' . $ownerId);

            return new LoginRequestResult(LoginRequestResult::CHECK_EMAIL);
        }

        if ($this->tokenService->hasRecentActiveOwnerLoginToken($ownerId, self::LOGIN_RESEND_COOLDOWN_SECONDS)) {
            return new LoginRequestResult(LoginRequestResult::CHECK_EMAIL);
        }

        $kermesseId   = (int) $kermesse['id'];
        $kermesseName = (string) $kermesse['name'];

        /** @var BaseConnection $db */
        $db = \Config\Database::connect();
        $db->transException(true);

        $rawToken   = null;
        $newTokenId = null;

        try {
            $db->transBegin();

            // Revoke all stale login tokens, then issue a fresh one atomically.
            $this->tokenService->revokeActiveOwnerLoginTokens($ownerId);
            $issuedToken = $this->tokenService->issueOwnerLoginToken($ownerId, $kermesseId, $email);
            $rawToken    = $issuedToken->rawToken;
            $newTokenId  = $issuedToken->tokenId;
            unset($issuedToken);

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'OwnerLoginService: failed to issue login token: ' . $e->getMessage());

            return new LoginRequestResult(LoginRequestResult::CHECK_EMAIL);
        }

        $baseUrl  = rtrim(config('Kermesse')->publicBaseURL, '/');
        $loginUrl = $baseUrl . '/owner/login/' . $rawToken;
        $rawToken = null;

        $emailResult = $this->emailService->sendOwnerLoginEmail(
            $email,
            $owner['display_name'],
            $kermesseName,
            $loginUrl,
        );

        if (! $emailResult->sent) {
            $this->tokenService->revokeActiveOwnerLoginTokens($ownerId);

            return new LoginRequestResult(LoginRequestResult::CHECK_EMAIL);
        }

        return new LoginRequestResult(LoginRequestResult::CHECK_EMAIL);
    }
}
