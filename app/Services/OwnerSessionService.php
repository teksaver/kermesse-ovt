<?php

namespace App\Services;

use App\Models\KermesseModel;
use App\Models\OwnerModel;

/**
 * Consumes an owner_login token and elevates the request to an admin session.
 *
 * The caller (controller) must call session()->regenerate(true) before setting
 * session keys to prevent session fixation.
 */
class OwnerSessionService
{
    private TokenService  $tokenService;
    private OwnerModel    $ownerModel;
    private KermesseModel $kermesseModel;

    public function __construct(
        ?TokenService  $tokenService  = null,
        ?OwnerModel    $ownerModel    = null,
        ?KermesseModel $kermesseModel = null,
    ) {
        $this->tokenService  = $tokenService  ?? new TokenService();
        $this->ownerModel    = $ownerModel    ?? model(OwnerModel::class);
        $this->kermesseModel = $kermesseModel ?? model(KermesseModel::class);
    }

    /**
     * Validate an owner_login token without consuming it (prefetch/GET confirmation check).
     *
     * Performs all owner and kermesse checks but does NOT mark the token as used.
     * Returns SUCCESS if the token is ready to be consumed, or an appropriate error status.
     */
    public function prevalidateLoginToken(string $rawToken): SessionOutcome
    {
        $result = $this->tokenService->validateOwnerLoginToken($rawToken);

        if (! $result->isValid()) {
            return new SessionOutcome($result->status);
        }

        $tokenRow   = $result->tokenRow;
        $ownerId    = (int) $tokenRow['owner_id'];
        $kermesseId = (int) $tokenRow['kermesse_id'];

        $owner = $this->ownerModel->find($ownerId);
        if ($owner === null || $owner['status'] !== 'active') {
            return new SessionOutcome(SessionOutcome::INVALID_TOKEN);
        }

        $kermesse = $this->kermesseModel
            ->where('id', $kermesseId)
            ->where('owner_id', $ownerId)
            ->first();
        if ($kermesse === null) {
            return new SessionOutcome(SessionOutcome::INVALID_TOKEN);
        }

        return new SessionOutcome(SessionOutcome::SUCCESS);
    }

    /**
     * Validate an owner_login token and prepare the session outcome.
     *
     * Does NOT touch the session — the controller is responsible for
     * regenerating and setting session keys on SUCCESS.
     *
     * @return SessionOutcome SUCCESS carries ownerId and kermesseId.
     */
    public function consumeLoginToken(string $rawToken): SessionOutcome
    {
        $result = $this->tokenService->validateOwnerLoginToken($rawToken);

        if (! $result->isValid()) {
            return new SessionOutcome($result->status);
        }

        $tokenRow   = $result->tokenRow;
        $ownerId    = (int) $tokenRow['owner_id'];
        $kermesseId = (int) $tokenRow['kermesse_id'];
        $tokenId    = (int) $tokenRow['id'];

        // Owner must still be active at consumption time.
        $owner = $this->ownerModel->find($ownerId);
        if ($owner === null || $owner['status'] !== 'active') {
            return new SessionOutcome(SessionOutcome::INVALID_TOKEN);
        }

        // Kermesse must still belong to this owner (scope check).
        $kermesse = $this->kermesseModel
            ->where('id', $kermesseId)
            ->where('owner_id', $ownerId)
            ->first();
        if ($kermesse === null) {
            return new SessionOutcome(SessionOutcome::INVALID_TOKEN);
        }

        /** @var \CodeIgniter\Database\BaseConnection $db */
        $db = \Config\Database::connect();
        $db->transException(true);

        try {
            $db->transBegin();

            $marked = $this->tokenService->markLoginTokenAsUsed($tokenId);
            if (! $marked) {
                // Concurrent claim — re-validate to return precise status (used/expired/revoked).
                $db->transRollback();
                $recheck = $this->tokenService->validateOwnerLoginToken($rawToken);

                return new SessionOutcome($recheck->isValid() ? SessionOutcome::ERROR : $recheck->status);
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'OwnerSessionService: failed to consume login token: ' . $e->getMessage());

            return new SessionOutcome(SessionOutcome::ERROR);
        }

        return new SessionOutcome(SessionOutcome::SUCCESS, $ownerId, $kermesseId);
    }
}
