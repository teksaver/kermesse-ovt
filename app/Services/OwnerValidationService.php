<?php

namespace App\Services;

use App\Models\KermesseModel;
use App\Models\OwnerModel;
use CodeIgniter\Database\BaseConnection;

/**
 * Orchestrates the owner email validation flow.
 *
 * Responsibilities:
 *   1. Validate the raw token via TokenService.
 *   2. Activate the owner and mark the token as used inside a DB transaction.
 *   3. Return a typed ValidationOutcome — never expose token internals to the caller.
 */
class OwnerValidationService
{
    private TokenService $tokenService;
    private OwnerModel   $ownerModel;
    private KermesseModel $kermesseModel;

    public function __construct(
        ?TokenService $tokenService = null,
        ?OwnerModel   $ownerModel   = null,
        ?KermesseModel $kermesseModel = null,
    ) {
        $this->tokenService  = $tokenService ?? new TokenService();
        $this->ownerModel    = $ownerModel   ?? model(OwnerModel::class);
        $this->kermesseModel = $kermesseModel ?? model(KermesseModel::class);
    }

    /**
     * Process a validation attempt using the raw token from the email link.
     *
     * The raw token is used only to compute the hash; it is never stored.
     */
    public function processValidation(string $rawToken): ValidationOutcome
    {
        $validationResult = $this->tokenService->validateOwnerToken($rawToken);

        if (! $validationResult->isValid()) {
            // Distinguish expired+pending from other failures so the controller
            // can offer the "me connecter" option when appropriate.
            if ($validationResult->status === TokenValidationResult::EXPIRED_TOKEN) {
                $tokenRow = $validationResult->tokenRow;

                if ($tokenRow !== null) {
                    $owner = $this->ownerModel->find((int) $tokenRow['owner_id']);

                    if ($owner !== null && $owner['status'] === 'owner_pending') {
                        return new ValidationOutcome(
                            ValidationOutcome::EXPIRED_PENDING,
                        );
                    }
                }
            }

            if ($validationResult->status === TokenValidationResult::USED_TOKEN) {
                return new ValidationOutcome(ValidationOutcome::USED_TOKEN);
            }

            if ($validationResult->status === TokenValidationResult::REVOKED_TOKEN) {
                return new ValidationOutcome(ValidationOutcome::REVOKED_TOKEN);
            }

            // All other failures: neutral response (invalid, used, revoked, etc.)
            return new ValidationOutcome(ValidationOutcome::INVALID_TOKEN);
        }

        $tokenRow    = $validationResult->tokenRow;
        $ownerId     = (int) $tokenRow['owner_id'];
        $kermesseId  = (int) $tokenRow['kermesse_id'];
        $tokenId     = (int) $tokenRow['id'];

        if ($ownerId <= 0 || $kermesseId <= 0) {
            return new ValidationOutcome(ValidationOutcome::INVALID_TOKEN);
        }

        // Verify owner exists and is still pending
        $owner = $this->ownerModel->find($ownerId);

        if ($owner === null) {
            return new ValidationOutcome(ValidationOutcome::INVALID_TOKEN);
        }

        if ($owner['status'] === 'active') {
            // Already validated — don't create another session via this token.
            return new ValidationOutcome(ValidationOutcome::ALREADY_ACTIVE);
        }

        if ($owner['status'] !== 'owner_pending') {
            return new ValidationOutcome(ValidationOutcome::INVALID_TOKEN);
        }

        $kermesse = $this->kermesseModel
            ->where('id', $kermesseId)
            ->where('owner_id', $ownerId)
            ->first();

        if ($kermesse === null) {
            return new ValidationOutcome(ValidationOutcome::INVALID_TOKEN);
        }

        // Atomic activation: update owner + mark token used in one transaction
        /** @var BaseConnection $db */
        $db = \Config\Database::connect();
        $db->transException(true);

        try {
            $db->transBegin();

            if (! $this->tokenService->markTokenAsUsed($tokenId)) {
                // Token was claimed by a concurrent request — it is now used.
                $db->transRollback();

                return new ValidationOutcome(ValidationOutcome::USED_TOKEN);
            }

            try {
                $this->ownerModel->skipValidation(true);
                // WHERE status = 'owner_pending' prevents double-activation if two
                // concurrent requests somehow both pass the pre-transaction check.
                $this->ownerModel->where('status', 'owner_pending');
                $updated = $this->ownerModel->update($ownerId, [
                    'status'            => 'active',
                    'email_verified_at' => date('Y-m-d H:i:s'),
                ]);
                if ($updated === false || $this->ownerModel->affectedRows() !== 1) {
                    throw new \RuntimeException('Owner activation failed: concurrent activation or status mismatch');
                }
            } finally {
                $this->ownerModel->skipValidation(false);
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'OwnerValidationService: ' . $e->getMessage());

            return new ValidationOutcome(ValidationOutcome::ERROR);
        }

        return new ValidationOutcome(
            status: ValidationOutcome::SUCCESS,
            ownerId: $ownerId,
            kermesseId: $kermesseId,
        );
    }
}
