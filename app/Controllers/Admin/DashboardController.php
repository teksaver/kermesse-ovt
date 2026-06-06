<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\KermesseModel;
use App\Services\AdminAuthorizationService;
use App\Services\AuthorizationResult;

/**
 * Minimal admin dashboard: GET /admin/kermesses/{kermesseId}
 *
 * Displays the kermesse name and its current status (preparation).
 * Access is guarded by AdminAuthorizationService — no session, wrong kermesse,
 * or non-active owner all result in a denial page or redirect.
 */
class DashboardController extends BaseController
{
    /**
     * Show the admin dashboard for a specific kermesse.
     *
     * @param string $kermesseId  Route segment (cast to int internally)
     */
    public function show(string $kermesseId): mixed
    {
        $id      = (int) $kermesseId;
        $service = new AdminAuthorizationService();
        $result  = $service->checkAccess($id);

        if (! $result->isAuthorized()) {
            return $this->handleDenial($result);
        }

        $kermesseModel = model(KermesseModel::class);
        $kermesse      = $kermesseModel
            ->where('id', $id)
            ->where('owner_id', (int) session('owner_id'))
            ->first();

        if ($kermesse === null) {
            return $this->handleDenial(new AuthorizationResult(AuthorizationResult::ACCESS_DENIED));
        }

        $flashSuccess = session()->getFlashdata('flash_success');

        return view('admin/dashboard', (new DashboardViewModelBuilder())->build($kermesse, [
            'flashSuccess' => is_string($flashSuccess) ? $flashSuccess : null,
        ]));
    }

    private function handleDenial(AuthorizationResult $result): mixed
    {
        if ($result->status === AuthorizationResult::NO_SESSION) {
            return redirect()->to(site_url('owner/login'));
        }

        if ($result->status === AuthorizationResult::PENDING_VALIDATION) {
            return service('response')->setStatusCode(403)->setBody(view('owner/validation_result', [
                'status'   => 'validation_required',
                'loginUrl' => site_url('owner/login'),
            ]));
        }

        // kermesse_mismatch or access_denied
        return service('response')->setStatusCode(403)->setBody(view('owner/validation_result', [
            'status'   => 'access_denied',
            'loginUrl' => site_url('owner/login'),
        ]));
    }
}
