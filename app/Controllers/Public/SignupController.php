<?php

namespace App\Controllers\Public;

use App\Controllers\BaseController;
use App\Services\PublicVolunteerPageService;
use App\Services\SignupService;
use App\Models\VolunteerModel;
use App\Models\SignupModel;

/**
 * Public signup form: GET/POST /k/{public_slug}/slots/{slot_id}/signup
 *
 * Displays and validates the volunteer signup form, then delegates inscription
 * creation to SignupService (find-or-create volunteer + transactional signup insert).
 * Returns neutral 404 for any slug/slot mismatch or non-open kermesse.
 *
 * PRIVACY: only slot summary data (kermesse name, stand name, time, availability) is
 * passed to views. No volunteer data, owner/admin fields, or management links.
 */
class SignupController extends BaseController
{
    public function show(string $publicSlug, string $slotId): mixed
    {
        $summary = (new PublicVolunteerPageService())->buildSlotSummary($publicSlug, (int) $slotId);

        if ($summary === null) {
            return $this->neutral404();
        }

        if ($summary['isFull']) {
            return redirect()->to(site_url("k/{$publicSlug}"));
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

        if ($summary['isFull']) {
            return redirect()->to(site_url("k/{$publicSlug}"))->with('error', 'Ce créneau est complet.');
        }

        $getPostString = function(string $key): string {
            $val = $this->request->getPost($key);
            return trim(is_array($val) ? '' : (string) $val);
        };

        $raw = [
            'first_name' => $getPostString('first_name'),
            'last_name'  => $getPostString('last_name'),
            'email'      => $getPostString('email'),
            'phone'      => $getPostString('phone'),
        ];

        $rules = [
            'first_name' => 'required|max_length[100]',
            'last_name'  => 'required|max_length[100]',
            'email'      => 'required|valid_email|max_length[254]',
            'phone'      => 'permit_empty|max_length[30]|regex_match[/^(?=.*[0-9])[0-9\+\-\(\)\s]+$/]',
        ];

        $validation = service('validation');
        if (! $validation->setRules($rules)->run($raw)) {
            return view('public/signup_form', [
                'summary' => $summary,
                'fields'  => $raw,
                'errors'  => $validation->getErrors(),
            ]);
        }

        $result = (new SignupService(
            new VolunteerModel(),
            new SignupModel(),
        ))->signup(
            slotId:      (int) $slotId,
            kermesseId:  (int) $summary['kermesseId'],
            fields:      $validation->getValidated(),
        );

        if (! $result->success) {
            return view('public/signup_form', [
                'summary' => $summary,
                'fields'  => $raw,
                'errors'  => ['_service' => 'Votre inscription n\'a pas pu être enregistrée. Veuillez réessayer.'],
            ]);
        }

        session()->setFlashdata('signup_success', true);
        return redirect()->to(site_url("k/{$publicSlug}/slots/{$slotId}/signup/confirmation"));
    }

    public function confirm(string $publicSlug, string $slotId): mixed
    {
        if (! session()->getFlashdata('signup_success')) {
            return redirect()->to(site_url("k/{$publicSlug}"));
        }

        $summary = (new PublicVolunteerPageService())->buildSlotSummary($publicSlug, (int) $slotId);

        if ($summary === null) {
            return $this->neutral404();
        }

        return view('public/signup_confirmation', [
            'kermesseName' => $summary['kermesseName'],
            'publicSlug'   => $publicSlug,
        ]);
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
