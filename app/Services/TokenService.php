<?php

namespace App\Services;

use App\Models\AccessTokenModel;
use Config\Kermesse as KermesseConfig;

class TokenService
{
    private AccessTokenModel $tokenModel;
    private KermesseConfig $config;

    public function __construct(?AccessTokenModel $tokenModel = null, ?KermesseConfig $config = null)
    {
        $this->tokenModel = $tokenModel ?? model(AccessTokenModel::class);
        $this->config     = $config ?? config('Kermesse');
    }

    /**
     * Issue an owner_validation token.
     *
     * The raw token is returned once for email link construction
     * and must never be logged or persisted in plain text.
     */
    public function issueOwnerValidationToken(int $ownerId, int $kermesseId, string $email): IssuedToken
    {
        if ($this->config->ownerValidationTokenTTL <= 0) {
            throw new \InvalidArgumentException('Invalid owner validation token TTL');
        }

        $rawBytes = random_bytes(32);
        $rawToken = rtrim(strtr(base64_encode($rawBytes), '+/', '-_'), '=');
        $hash     = hash('sha256', $rawToken);

        $ttl       = $this->config->ownerValidationTokenTTL;
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

        try {
            $this->tokenModel->skipValidation(true);
            $tokenId = $this->tokenModel->insert([
                'token_hash'  => $hash,
                'token_type'  => 'owner_validation',
                'owner_id'    => $ownerId,
                'kermesse_id' => $kermesseId,
                'email'       => $email,
                'expires_at'  => $expiresAt,
                'used_at'     => null,
                'revoked_at'  => null,
            ]);
            if ($tokenId === false) {
                throw new \RuntimeException('Owner validation token insert failed');
            }
        } finally {
            $this->tokenModel->skipValidation(false);
        }

        return new IssuedToken($rawToken, (int) $tokenId);
    }

    /**
     * Validate a raw owner_validation token.
     *
     * Hashes the raw token and looks it up in the DB.
     * The raw token must never be stored or logged.
     */
    public function validateOwnerToken(string $rawToken): TokenValidationResult
    {
        $hash  = hash('sha256', $rawToken);
        $token = $this->tokenModel
            ->where('token_hash', $hash)
            ->where('token_type', 'owner_validation')
            ->first();

        if ($token === null) {
            return new TokenValidationResult(TokenValidationResult::INVALID_TOKEN);
        }

        if ($token['revoked_at'] !== null) {
            return new TokenValidationResult(TokenValidationResult::REVOKED_TOKEN, $token);
        }

        if ($token['used_at'] !== null) {
            return new TokenValidationResult(TokenValidationResult::USED_TOKEN, $token);
        }

        if (empty($token['owner_id']) || empty($token['kermesse_id'])) {
            return new TokenValidationResult(TokenValidationResult::INVALID_TOKEN, $token);
        }

        $expiresAt = strtotime((string) $token['expires_at']);
        if ($expiresAt === false) {
            return new TokenValidationResult(TokenValidationResult::INVALID_TOKEN, $token);
        }

        if ($expiresAt <= time()) {
            return new TokenValidationResult(TokenValidationResult::EXPIRED_TOKEN, $token);
        }

        return new TokenValidationResult(TokenValidationResult::VALID, $token);
    }

    /**
     * Mark a token as used (sets used_at to now).
     *
     * Must be called inside a transaction when coupled with owner activation.
     */
    public function markTokenAsUsed(int $tokenId): bool
    {
        $this->tokenModel
            ->where('id', $tokenId)
            ->where('token_type', 'owner_validation')
            ->where('used_at', null)
            ->where('revoked_at', null)
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->set(['used_at' => date('Y-m-d H:i:s')])
            ->update();

        return $this->tokenModel->affectedRows() === 1;
    }

    /**
     * Check whether a pending owner already has a recent usable validation token.
     */
    public function hasRecentActiveOwnerValidationToken(int $ownerId, int $cooldownSeconds): bool
    {
        $createdAfter = date('Y-m-d H:i:s', time() - $cooldownSeconds);

        $token = $this->tokenModel
            ->where('owner_id', $ownerId)
            ->where('token_type', 'owner_validation')
            ->where('revoked_at', null)
            ->where('used_at', null)
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->where('created_at >=', $createdAfter)
            ->first();

        return $token !== null;
    }

    /**
     * Revoke all active owner_validation tokens for the given owner.
     *
     * Used before re-issuing a new validation link so that stale links
     * cannot be replayed.
     */
    public function revokeActiveOwnerValidationTokens(int $ownerId, ?int $exceptTokenId = null): void
    {
        $now = date('Y-m-d H:i:s');

        $query = $this->tokenModel
            ->where('owner_id', $ownerId)
            ->where('token_type', 'owner_validation')
            ->where('revoked_at', null)
            ->where('used_at', null)
            ->where('expires_at >', $now);

        if ($exceptTokenId !== null) {
            $query = $query->where('id !=', $exceptTokenId);
        }

        $query->set(['revoked_at' => $now])->update();
    }

    public function revokeOlderActiveOwnerValidationTokens(int $ownerId, int $newTokenId): void
    {
        $this->tokenModel
            ->where('owner_id', $ownerId)
            ->where('token_type', 'owner_validation')
            ->where('id <', $newTokenId)
            ->where('revoked_at', null)
            ->where('used_at', null)
            ->set(['revoked_at' => date('Y-m-d H:i:s')])
            ->update();
    }

    public function revokeToken(int $tokenId): void
    {
        $this->tokenModel
            ->where('id', $tokenId)
            ->where('token_type', 'owner_validation')
            ->where('used_at', null)
            ->where('revoked_at', null)
            ->set(['revoked_at' => date('Y-m-d H:i:s')])
            ->update();
    }

    // ------------------------------------------------------------------
    // magic_link token methods (Story 1.3 — universal login)
    // ------------------------------------------------------------------

    /**
     * Issue a magic_link token tied to an email address.
     *
     * The user may not exist yet at request time, so no user_id is stored.
     * The raw token is returned once for link construction and must never
     * be logged or persisted in plain text.
     */
    public function issueMagicLink(string $email): IssuedToken
    {
        if ($this->config->magicLinkTokenTTL <= 0) {
            throw new \InvalidArgumentException('Invalid magic link token TTL');
        }

        $rawBytes = random_bytes(32);
        $rawToken = rtrim(strtr(base64_encode($rawBytes), '+/', '-_'), '=');
        $hash     = hash('sha256', $rawToken);

        $ttl       = $this->config->magicLinkTokenTTL;
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

        try {
            $this->tokenModel->skipValidation(true);
            $tokenId = $this->tokenModel->insert([
                'token_hash'  => $hash,
                'token_type'  => 'magic_link',
                'user_id'     => null,
                'email'       => $email,
                'expires_at'  => $expiresAt,
                'used_at'     => null,
                'revoked_at'  => null,
            ]);
            if ($tokenId === false) {
                throw new \RuntimeException('Magic link token insert failed');
            }
        } finally {
            $this->tokenModel->skipValidation(false);
        }

        return new IssuedToken($rawToken, (int) $tokenId);
    }

    // ------------------------------------------------------------------
    // owner_login token methods (Story 1.6)
    // ------------------------------------------------------------------

    /**
     * Issue an owner_login token.
     *
     * The raw token is returned once for email link construction
     * and must never be logged or persisted in plain text.
     */
    public function issueOwnerLoginToken(int $ownerId, int $kermesseId, string $email): IssuedToken
    {
        if ($this->config->ownerLoginTokenTTL <= 0) {
            throw new \InvalidArgumentException('Invalid owner login token TTL');
        }

        $rawBytes = random_bytes(32);
        $rawToken = rtrim(strtr(base64_encode($rawBytes), '+/', '-_'), '=');
        $hash     = hash('sha256', $rawToken);

        $ttl       = $this->config->ownerLoginTokenTTL;
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

        try {
            $this->tokenModel->skipValidation(true);
            $tokenId = $this->tokenModel->insert([
                'token_hash'  => $hash,
                'token_type'  => 'owner_login',
                'owner_id'    => $ownerId,
                'kermesse_id' => $kermesseId,
                'email'       => $email,
                'expires_at'  => $expiresAt,
                'used_at'     => null,
                'revoked_at'  => null,
            ]);
            if ($tokenId === false) {
                throw new \RuntimeException('Owner login token insert failed');
            }
        } finally {
            $this->tokenModel->skipValidation(false);
        }

        return new IssuedToken($rawToken, (int) $tokenId);
    }

    /**
     * Validate a raw owner_login token.
     *
     * Hashes the raw token and looks it up in the DB.
     * The raw token must never be stored or logged.
     */
    public function validateOwnerLoginToken(string $rawToken): TokenValidationResult
    {
        $hash  = hash('sha256', $rawToken);
        $token = $this->tokenModel
            ->where('token_hash', $hash)
            ->where('token_type', 'owner_login')
            ->first();

        return $this->validateOwnerLoginTokenRow($token);
    }

    public function validateOwnerLoginTokenById(int $tokenId): TokenValidationResult
    {
        $token = $this->tokenModel
            ->where('id', $tokenId)
            ->where('token_type', 'owner_login')
            ->first();

        return $this->validateOwnerLoginTokenRow($token);
    }

    /**
     * @param array<string, mixed>|null $token
     */
    private function validateOwnerLoginTokenRow(?array $token): TokenValidationResult
    {
        if ($token === null) {
            return new TokenValidationResult(TokenValidationResult::INVALID_TOKEN);
        }

        if ($token['revoked_at'] !== null) {
            return new TokenValidationResult(TokenValidationResult::REVOKED_TOKEN, $token);
        }

        if ($token['used_at'] !== null) {
            return new TokenValidationResult(TokenValidationResult::USED_TOKEN, $token);
        }

        if (empty($token['owner_id']) || empty($token['kermesse_id'])) {
            return new TokenValidationResult(TokenValidationResult::INVALID_TOKEN, $token);
        }

        $expiresAt = strtotime((string) $token['expires_at']);
        if ($expiresAt === false) {
            return new TokenValidationResult(TokenValidationResult::INVALID_TOKEN, $token);
        }

        if ($expiresAt <= time()) {
            return new TokenValidationResult(TokenValidationResult::EXPIRED_TOKEN, $token);
        }

        return new TokenValidationResult(TokenValidationResult::VALID, $token);
    }

    /**
     * Mark an owner_login token as used (atomic: WHERE token_type + used_at IS NULL + not expired).
     *
     * Must be called inside a transaction. Returns false if the row was already claimed
     * (concurrent request), in which case the caller must abort session creation.
     */
    public function markLoginTokenAsUsed(int $tokenId): bool
    {
        $this->tokenModel
            ->where('id', $tokenId)
            ->where('token_type', 'owner_login')
            ->where('used_at', null)
            ->where('revoked_at', null)
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->set(['used_at' => date('Y-m-d H:i:s')])
            ->update();

        return $this->tokenModel->affectedRows() === 1;
    }

    /**
     * Check whether an owner already has a recent usable owner_login token (cooldown guard).
     */
    public function hasRecentActiveOwnerLoginToken(int $ownerId, int $cooldownSeconds): bool
    {
        $createdAfter = date('Y-m-d H:i:s', time() - $cooldownSeconds);

        $token = $this->tokenModel
            ->where('owner_id', $ownerId)
            ->where('token_type', 'owner_login')
            ->where('revoked_at', null)
            ->where('used_at', null)
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->where('created_at >=', $createdAfter)
            ->first();

        return $token !== null;
    }

    /**
     * Revoke a single owner_login token by ID.
     *
     * Used when email delivery fails: revokes only the undelivered token
     * so that previously-issued (older) links remain usable.
     */
    public function revokeLoginToken(int $tokenId): void
    {
        $this->tokenModel
            ->where('id', $tokenId)
            ->where('token_type', 'owner_login')
            ->where('used_at', null)
            ->where('revoked_at', null)
            ->set(['revoked_at' => date('Y-m-d H:i:s')])
            ->update();
    }

    /**
     * Revoke all active owner_login tokens for the given owner.
     *
     * Pass $exceptTokenId to preserve a specific token (e.g. the newly issued one).
     */
    public function revokeActiveOwnerLoginTokens(int $ownerId, ?int $exceptTokenId = null): void
    {
        $now = date('Y-m-d H:i:s');

        $query = $this->tokenModel
            ->where('owner_id', $ownerId)
            ->where('token_type', 'owner_login')
            ->where('revoked_at', null)
            ->where('used_at', null)
            ->where('expires_at >', $now);

        if ($exceptTokenId !== null) {
            $query = $query->where('id !=', $exceptTokenId);
        }

        $query->set(['revoked_at' => $now])->update();
    }
}
