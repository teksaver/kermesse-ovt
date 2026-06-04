<?php

namespace App\Controllers\Owner;

use App\Controllers\BaseController;
use App\Services\OwnerValidationService;
use App\Services\ValidationOutcome;

/**
 * Handles the owner email validation link: GET /owner/validate/{token}
 *
 * The token from the email link is validated by OwnerValidationService.
 * On success, an admin session is created and the owner is redirected to
 * the minimal admin area. On any failure, a result page is rendered.
 */
class ValidationController extends BaseController
{
    /**
     * Process the validation token from the email link.
     *
     * @param string $rawToken  URL-safe base64 token from the email
     */
    public function handleValidation(string $rawToken): mixed
    {
        $service = new OwnerValidationService();
        $outcome = $service->processValidation($rawToken);

        if ($outcome->isSuccess()) {
            // Create admin session — keys must match AdminAuthorizationService expectations
            $session = session();
            $session->regenerate(true);
            $session->set([
                'owner_id'                 => $outcome->ownerId,
                'kermesse_id'              => $outcome->kermesseId,
                'owner_admin_authenticated' => true,
            ]);

            return redirect()->to(site_url('admin/kermesses/' . $outcome->kermesseId));
        }

        // Map outcome to view context
        $viewStatus = match ($outcome->status) {
            ValidationOutcome::EXPIRED_PENDING => 'expired',
            ValidationOutcome::USED_TOKEN      => 'used',
            ValidationOutcome::REVOKED_TOKEN   => 'revoked',
            ValidationOutcome::ALREADY_ACTIVE  => 'already_active',
            default                            => 'invalid',
        };

        return view('owner/validation_result', [
            'status'   => $viewStatus,
            'loginUrl' => site_url('owner/login'),
        ]);
    }
}
