<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\KermesseModel;
use App\Models\StandModel;
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

        return view('admin/dashboard', $this->buildViewModel($kermesse));
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private function buildViewModel(array $kermesse): array
    {
        $statusMap = [
            'preparation' => ['label' => 'Inscriptions en préparation', 'class' => 'status-badge--preparation'],
            'open'        => ['label' => 'Inscriptions ouvertes',        'class' => 'status-badge--open'],
            'closed'      => ['label' => 'Inscriptions fermées',         'class' => 'status-badge--closed'],
        ];

        $status     = $kermesse['status'] ?? 'preparation';
        $statusInfo = $statusMap[$status] ?? $statusMap['preparation'];

        $standModel = model(StandModel::class);
        $stands     = $standModel->getActiveForKermesse((int) $kermesse['id']);

        // Flash data from stand form submissions
        $standErrors    = session()->getFlashdata('stand_errors') ?? [];
        $standInputName = session()->getFlashdata('stand_input_name') ?? '';
        $standEditId    = session()->getFlashdata('stand_edit_id');
        $flashSuccess   = session()->getFlashdata('flash_success');

        return [
            'kermesse'       => $kermesse,
            'statusLabel'    => $statusInfo['label'],
            'statusClass'    => $statusInfo['class'],
            'stands'         => $stands,
            'hasStands'      => count($stands) > 0,
            'isOpen'         => $status === 'open',
            'disabledReason' => 'Ajoutez au moins un stand avec un créneau avant d\'ouvrir les inscriptions.',
            'standErrors'    => $standErrors,
            'standInputName' => $standInputName,
            'standEditId'    => $standEditId,
            'flashSuccess'   => is_string($flashSuccess) ? $flashSuccess : null,
        ];
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
