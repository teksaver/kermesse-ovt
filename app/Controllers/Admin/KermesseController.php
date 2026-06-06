<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\KermesseModel;
use App\Services\AdminAuthorizationService;
use App\Services\AuthorizationResult;
use App\Services\KermesseLifecycleService;

/**
 * Admin lifecycle actions: open / close signups for a kermesse.
 *
 * POST /admin/kermesses/{id}/open
 * POST /admin/kermesses/{id}/close
 *
 * Transitions are owned by KermesseLifecycleService; this controller only
 * orchestrates HTTP: authorize, load the owner-scoped kermesse, delegate, redirect.
 */
class KermesseController extends BaseController
{
    public function open(string $kermesseId): mixed
    {
        $id       = (int) $kermesseId;
        $kermesse = $this->authorizeAndLoad($id, $denial);
        if ($kermesse === null) {
            return $denial;
        }

        $result = (new KermesseLifecycleService())->open($id, (int) session('owner_id'));

        if ($result === KermesseLifecycleService::RESULT_NOT_PUBLISHABLE) {
            // Preserve the current state and re-render the dashboard (no redirect)
            // with the exact blocking message, keeping entered configuration intact.
            return view('admin/dashboard', (new DashboardViewModelBuilder())->build($kermesse, [
                'lifecycleError' => KermesseLifecycleService::REASON_NOT_PUBLISHABLE,
            ]));
        }

        return redirect()
            ->to(site_url("admin/kermesses/{$id}"))
            ->with('flash_success', 'Inscriptions ouvertes.');
    }

    public function close(string $kermesseId): mixed
    {
        $id       = (int) $kermesseId;
        $kermesse = $this->authorizeAndLoad($id, $denial);
        if ($kermesse === null) {
            return $denial;
        }

        (new KermesseLifecycleService())->close($id, (int) session('owner_id'));

        return redirect()
            ->to(site_url("admin/kermesses/{$id}"))
            ->with('flash_success', 'Inscriptions fermées.');
    }

    /**
     * Authorize the session for the kermesse and load it owner-scoped.
     * On failure, sets $denial to the response to return and returns null.
     *
     * @param-out mixed $denial
     * @return array<string, mixed>|null
     */
    private function authorizeAndLoad(int $id, mixed &$denial): ?array
    {
        $result = (new AdminAuthorizationService())->checkAccess($id);
        if (! $result->isAuthorized()) {
            $denial = $this->denyAccess($result);

            return null;
        }

        $kermesse = model(KermesseModel::class)
            ->where('id', $id)
            ->where('owner_id', (int) session('owner_id'))
            ->first();

        if ($kermesse === null) {
            $denial = $this->denyAccess(new AuthorizationResult(AuthorizationResult::ACCESS_DENIED));

            return null;
        }

        $denial = null;

        return $kermesse;
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
