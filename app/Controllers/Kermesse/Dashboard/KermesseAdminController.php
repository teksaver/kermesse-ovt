<?php

namespace App\Controllers\Kermesse\Dashboard;

use App\Controllers\BaseController;
use App\Models\KermesseModel;

/**
 * Kermesse admin dashboard: stands, slots, lifecycle, participants.
 * Implemented incrementally in Stories 2.1–2.5 and 4.4.
 */
class KermesseAdminController extends BaseController
{
    /** GET /kermesse/{id} */
    public function show(string $kermesseId): mixed
    {
        $id       = (int) $kermesseId;
        $kermesse = model(KermesseModel::class)->find($id);

        if ($kermesse === null) {
            return $this->response->setStatusCode(404)->setBody(view('errors/html/error_404'));
        }

        return view('kermesse/dashboard', [
            'title'    => esc($kermesse['name']),
            'kermesse' => $kermesse,
        ]);
    }
}
