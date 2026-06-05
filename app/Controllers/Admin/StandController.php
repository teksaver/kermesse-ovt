<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\KermesseModel;
use App\Models\StandModel;
use App\Services\AdminAuthorizationService;
use App\Services\AuthorizationResult;
use CodeIgniter\Database\Exceptions\DatabaseException;

class StandController extends BaseController
{
    public function create(string $kermesseId): mixed
    {
        $id      = (int) $kermesseId;
        $service = new AdminAuthorizationService();
        $result  = $service->checkAccess($id);

        if (! $result->isAuthorized()) {
            return $this->denyAccess($result);
        }

        $kermesse = $this->loadOwnedKermesse($id);
        if ($kermesse === null) {
            return $this->denyAccess(new AuthorizationResult(AuthorizationResult::ACCESS_DENIED));
        }

        $nameResult = $this->standNameFromPost();
        $name       = $nameResult['name'];
        if (! $nameResult['isValidInput']) {
            return $this->renderWithErrors($kermesse, ['name' => 'Indiquez le nom du stand.'], '', null);
        }

        $errors = $this->validateStandName($name, $id);

        if (! empty($errors)) {
            return $this->renderWithErrors($kermesse, $errors, $name, null);
        }

        $standModel = model(StandModel::class);
        try {
            $standModel->insert([
                'kermesse_id'   => $id,
                'name'          => $name,
                'display_order' => $standModel->nextDisplayOrder($id),
                'status'        => 'active',
            ]);
        } catch (DatabaseException $e) {
            if (! $this->isDuplicateStandNameError($e)) {
                throw $e;
            }

            return $this->renderWithErrors($kermesse, ['name' => 'Un stand porte déjà ce nom.'], $name, null);
        }

        return redirect()
            ->to(site_url("admin/kermesses/{$id}"))
            ->with('flash_success', 'Stand ajouté.');
    }

    public function update(string $kermesseId, string $standId): mixed
    {
        $id      = (int) $kermesseId;
        $sid     = (int) $standId;
        $service = new AdminAuthorizationService();
        $result  = $service->checkAccess($id);

        if (! $result->isAuthorized()) {
            return $this->denyAccess($result);
        }

        $kermesse   = $this->loadOwnedKermesse($id);
        $standModel = model(StandModel::class);
        $stand      = $standModel->where('id', $sid)->where('kermesse_id', $id)->first();

        if ($kermesse === null || $stand === null) {
            return $this->denyAccess(new AuthorizationResult(AuthorizationResult::ACCESS_DENIED));
        }

        $nameResult = $this->standNameFromPost();
        $name       = $nameResult['name'];
        if (! $nameResult['isValidInput']) {
            return $this->renderWithErrors($kermesse, ['name' => 'Indiquez le nom du stand.'], '', $sid);
        }

        $errors = $this->validateStandName($name, $id, $sid);

        if (! empty($errors)) {
            return $this->renderWithErrors($kermesse, $errors, $name, $sid);
        }

        try {
            $standModel->update($sid, ['name' => $name]);
        } catch (DatabaseException $e) {
            if (! $this->isDuplicateStandNameError($e)) {
                throw $e;
            }

            return $this->renderWithErrors($kermesse, ['name' => 'Un stand porte déjà ce nom.'], $name, $sid);
        }

        return redirect()
            ->to(site_url("admin/kermesses/{$id}"))
            ->with('flash_success', 'Stand enregistré.');
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private function loadOwnedKermesse(int $id): ?array
    {
        return model(KermesseModel::class)
            ->where('id', $id)
            ->where('owner_id', (int) session('owner_id'))
            ->first();
    }

    /**
     * @return array{name: string, isValidInput: bool}
     */
    private function standNameFromPost(): array
    {
        $rawName = $this->request->getPost('name');

        if (! is_string($rawName)) {
            return ['name' => '', 'isValidInput' => false];
        }

        $name = preg_replace('/\p{Cf}+/u', '', $rawName);
        if ($name === null) {
            $name = $rawName;
        }

        $name = preg_replace('/[\p{Z}\s]+/u', ' ', $name);
        if ($name === null) {
            $name = $rawName;
        }

        return ['name' => trim($name), 'isValidInput' => true];
    }

    /**
     * @return array<string, string>
     */
    private function validateStandName(string $name, int $kermesseId, ?int $excludeId = null): array
    {
        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Indiquez le nom du stand.';
            return $errors;
        }

        if (mb_strlen($name) > 255) {
            $errors['name'] = 'Le nom du stand ne peut pas dépasser 255 caractères.';
            return $errors;
        }

        $standModel = model(StandModel::class);
        if ($standModel->hasActiveDuplicate($kermesseId, $name, $excludeId)) {
            $errors['name'] = 'Un stand porte déjà ce nom.';
        }

        return $errors;
    }

    /**
     * Re-render the full dashboard view with validation errors (no redirect).
     */
    private function renderWithErrors(array $kermesse, array $errors, string $inputName, ?int $editStandId): mixed
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

        return view('admin/dashboard', [
            'kermesse'       => $kermesse,
            'statusLabel'    => $statusInfo['label'],
            'statusClass'    => $statusInfo['class'],
            'stands'         => $stands,
            'hasStands'      => count($stands) > 0,
            'isOpen'         => $status === 'open',
            'disabledReason' => 'Ajoutez au moins un stand avec un créneau avant d\'ouvrir les inscriptions.',
            'standErrors'    => $errors,
            'standInputName' => $inputName,
            'standEditId'    => $editStandId,
            'flashSuccess'   => null,
        ]);
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

    private function isDuplicateStandNameError(DatabaseException $e): bool
    {
        return $e->getCode() === 1062
            || str_contains($e->getMessage(), 'uq_stands_active_name')
            || str_contains($e->getMessage(), 'Duplicate entry');
    }
}
