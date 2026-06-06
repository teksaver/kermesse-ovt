<?php

namespace App\Controllers\Public;

use App\Controllers\BaseController;
use App\Services\PublicVolunteerPageService;

/**
 * Public signup form: GET/POST /k/{public_slug}/slots/{slot_id}/signup
 *
 * Displays and validates the volunteer signup form. Does NOT create an inscription —
 * that is handled by SignupService in Story 3.3. Returns neutral 404 for any slug/slot
 * mismatch or non-open kermesse, without revealing internal state.
 *
 * PRIVACY: only slot summary data (kermesse name, stand name, time, availability) is
 * passed to the view. No volunteer data, owner/admin fields, or management links.
 */
class SignupController extends BaseController
{
    public function show(string $publicSlug, string $slotId): mixed
    {
        $summary = (new PublicVolunteerPageService())->buildSlotSummary($publicSlug, (int) $slotId);

        if ($summary === null) {
            return $this->neutral404();
        }

        return view('public/signup_form', [
            'summary' => $summary,
            'fields'  => ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => ''],
            'errors'  => [],
        ]);
    }

    public function submit(string $publicSlug, string $slotId): mixed
    {
        $summary = (new PublicVolunteerPageService())->buildSlotSummary($publicSlug, (int) $slotId);

        if ($summary === null) {
            return $this->neutral404();
        }

        $raw = [
            'first_name' => trim((string) $this->request->getPost('first_name')),
            'last_name'  => trim((string) $this->request->getPost('last_name')),
            'email'      => trim((string) $this->request->getPost('email')),
            'phone'      => trim((string) $this->request->getPost('phone')),
        ];

        $rules = [
            'first_name' => 'required|max_length[100]',
            'last_name'  => 'required|max_length[100]',
            'email'      => 'required|valid_email|max_length[254]',
            'phone'      => 'permit_empty|max_length[30]',
        ];

        $validation = service('validation');
        if (! $validation->setRules($rules)->run($raw)) {
            return view('public/signup_form', [
                'summary' => $summary,
                'fields'  => $raw,
                'errors'  => $validation->getErrors(),
            ]);
        }

        // Validated fields are ready for SignupService (Story 3.3).
        // Until then, redirect back to the volunteer page without creating an inscription.
        return redirect()->to(site_url("k/{$publicSlug}"));
    }

    private function neutral404(): mixed
    {
        return service('response')
            ->setStatusCode(404)
            ->setBody(view('errors/html/error_404', [
                'message' => 'Cette page n\'existe pas ou n\'est plus disponible.',
            ]));
    }
}
