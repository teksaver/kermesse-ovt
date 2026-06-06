<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\KermesseModel;
use App\Services\AdminAuthorizationService;
use App\Services\AuthorizationResult;

/**
 * Read-only admin preview of the planning: GET /admin/kermesses/{id}/preview
 *
 * This is an ADMIN tool, not the public volunteer page (Epic 3). It renders the
 * configured stands and active slots with capacity/remaining counters, but never
 * a signup form, a token, a management link, or any volunteer personal data.
 * It must work for a kermesse in preparation, open, or closed.
 */
class PreviewController extends BaseController
{
    public function show(string $kermesseId): mixed
    {
        $id     = (int) $kermesseId;
        $result = (new AdminAuthorizationService())->checkAccess($id);
        if (! $result->isAuthorized()) {
            return $this->denyAccess($result);
        }

        $kermesse = model(KermesseModel::class)
            ->where('id', $id)
            ->where('owner_id', (int) session('owner_id'))
            ->first();

        if ($kermesse === null) {
            return $this->denyAccess(new AuthorizationResult(AuthorizationResult::ACCESS_DENIED));
        }

        // Reuse the shared builder so the preview cannot diverge from the dashboard's
        // active-stand/active-slot/remaining-spots definitions.
        $viewModel = (new DashboardViewModelBuilder())->build($kermesse);

        $totalCapacity  = 0;
        $totalRemaining = 0;
        foreach ($viewModel['stands'] as $stand) {
            foreach ($stand['slots'] ?? [] as $slot) {
                $totalCapacity  += (int) $slot['capacity'];
                $totalRemaining += (int) $slot['remainingSpots'];
            }
        }

        return view('admin/preview', array_merge($viewModel, [
            'dashboardUrl'   => site_url("admin/kermesses/{$id}"),
            'totalCapacity'  => $totalCapacity,
            'totalRemaining' => $totalRemaining,
        ]));
    }

    private function denyAccess(AuthorizationResult $result): mixed
    {
        if ($result->status === AuthorizationResult::NO_SESSION) {
            return redirect()->to(site_url('owner/login'));
        }

        if ($result->status === AuthorizationResult::PENDING_VALIDATION) {
            return service('response')
                ->setStatusCode(403)
                ->setBody(view('owner/validation_result', [
                    'status'   => 'validation_required',
                    'loginUrl' => site_url('owner/login'),
                ]));
        }

        return service('response')
            ->setStatusCode(403)
            ->setBody(view('owner/validation_result', [
                'status'   => 'access_denied',
                'loginUrl' => site_url('owner/login'),
            ]));
    }
}
