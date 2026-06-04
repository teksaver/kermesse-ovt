<?php

namespace App\Services;

use App\Models\KermesseModel;
use App\Models\OwnerModel;

/**
 * Verifies that the current session is authorized to access a given kermesse's admin area.
 *
 * Session keys expected:
 *   - owner_admin_authenticated (bool)  : set to true after successful email validation
 *   - owner_id                  (int)   : authenticated owner
 *   - kermesse_id               (int)   : kermesse the owner validated for
 */
class AdminAuthorizationService
{
    private OwnerModel $ownerModel;
    private KermesseModel $kermesseModel;

    public function __construct(?OwnerModel $ownerModel = null, ?KermesseModel $kermesseModel = null)
    {
        $this->ownerModel    = $ownerModel ?? model(OwnerModel::class);
        $this->kermesseModel = $kermesseModel ?? model(KermesseModel::class);
    }

    /**
     * Check whether the current session is allowed to access the admin area
     * for the given kermesse.
     */
    public function checkAccess(int $kermesseId): AuthorizationResult
    {
        $session = session();

        // Must have an explicit admin authentication flag
        if ($session->get('owner_admin_authenticated') !== true) {
            return new AuthorizationResult(AuthorizationResult::NO_SESSION);
        }

        $sessionOwnerId    = (int) $session->get('owner_id');
        $sessionKermesseId = (int) $session->get('kermesse_id');

        // The kermesse in the URL must match the one that was authenticated
        if ($sessionKermesseId !== $kermesseId) {
            return new AuthorizationResult(AuthorizationResult::KERMESSE_MISMATCH);
        }

        // Verify the owner is still active (guard against stale sessions)
        $owner = $this->ownerModel->find($sessionOwnerId);

        if ($owner === null) {
            return new AuthorizationResult(AuthorizationResult::ACCESS_DENIED);
        }

        if ($owner['status'] === 'owner_pending') {
            return new AuthorizationResult(AuthorizationResult::PENDING_VALIDATION);
        }

        if ($owner['status'] !== 'active') {
            return new AuthorizationResult(AuthorizationResult::ACCESS_DENIED);
        }

        $kermesse = $this->kermesseModel
            ->where('id', $kermesseId)
            ->where('owner_id', $sessionOwnerId)
            ->first();

        if ($kermesse === null) {
            return new AuthorizationResult(AuthorizationResult::ACCESS_DENIED);
        }

        return new AuthorizationResult(AuthorizationResult::AUTHORIZED);
    }
}
