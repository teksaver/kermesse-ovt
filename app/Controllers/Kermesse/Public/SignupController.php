<?php

namespace App\Controllers\Kermesse\Public;

use App\Controllers\BaseController;
use App\Models\KermesseModel;
use App\Models\ProfileDivergenceModel;
use App\Models\SignupModel;
use App\Models\SlotModel;
use App\Models\UserModel;
use App\Services\PublicVolunteerPageService;
use App\Services\SignupResult;
use App\Services\SignupService;

/**
 * Public signup form: GET/POST /k/{public_slug}/slots/{slot_id}/signup
 *
 * Displays and validates the volunteer signup form, then delegates inscription
 * creation to SignupService (find-or-create user + transactional signup insert).
 * Returns neutral 404 for any slug/slot mismatch or non-open kermesse.
 *
 * PRIVACY: only slot summary data (kermesse name, stand name, time, availability) is
 * passed to views. No user data, admin fields, or management links.
 */
class SignupController extends BaseController
{
    public function show(string $publicSlug, string $slotId): mixed
    {
        $summary = (new PublicVolunteerPageService())->buildSlotSummary($publicSlug, (int) $slotId);

        if ($summary === null) {
            return $this->neutral404();
        }

        if ($summary['kermesseStatus'] !== KermesseModel::STATUS_OPEN) {
            return redirect()->to(site_url("k/{$publicSlug}"));
        }

        if ($summary['isFull']) {
            return redirect()->to(site_url("k/{$publicSlug}"));
        }

        // Connected user: load profile from DB — DB data always takes precedence over
        // volunteer_identity session (NFR4: never trust client-supplied identity for
        // a session-authenticated user).
        $authUserId      = (int) session()->get('user_id');
        $isAuthenticated = session()->get('is_logged_in') === true && $authUserId > 0;

        if ($isAuthenticated) {
            $user = (new UserModel())->find($authUserId);
            if ($user !== null) {
                return view('kermesse/public/signup_form', [
                    'summary'         => $summary,
                    'fields'          => [
                        'first_name' => (string) $user['first_name'],
                        'last_name'  => (string) $user['last_name'],
                        'email'      => (string) $user['email'],
                        'phone'      => (string) ($user['phone'] ?? ''),
                    ],
                    'errors'          => [],
                    'isAuthenticated' => true,
                ]);
            }
            // Stale session (user_id not in DB): fall through to anonymous flow.
        }

        $identity = session()->get('volunteer_identity');
        $fields   = [
            'first_name' => (string) ($identity['first_name'] ?? ''),
            'last_name'  => (string) ($identity['last_name']  ?? ''),
            'email'      => (string) ($identity['email']      ?? ''),
            'phone'      => '',
        ];

        return view('kermesse/public/signup_form', [
            'summary'         => $summary,
            'fields'          => $fields,
            'errors'          => [],
            'isAuthenticated' => false,
        ]);
    }

    public function submit(string $publicSlug, string $slotId): mixed
    {
        $summary = (new PublicVolunteerPageService())->buildSlotSummary($publicSlug, (int) $slotId);

        if ($summary === null) {
            return $this->neutral404();
        }

        if ($summary['kermesseStatus'] !== KermesseModel::STATUS_OPEN) {
            return redirect()->to(site_url("k/{$publicSlug}"));
        }

        // Connected user: use DB profile directly — never trust POST fields for
        // identity (NFR4). This also avoids false-positive profile divergences.
        $authUserId      = (int) session()->get('user_id');
        $isAuthenticated = session()->get('is_logged_in') === true && $authUserId > 0;

        if ($isAuthenticated) {
            $user = (new UserModel())->find($authUserId);
            if ($user !== null) {
                $raw = [
                    'first_name' => (string) $user['first_name'],
                    'last_name'  => (string) $user['last_name'],
                    'email'      => (string) $user['email'],
                    'phone'      => (string) ($user['phone'] ?? ''),
                ];

                $result = (new SignupService(
                    userModel:              new UserModel(),
                    signupModel:            new SignupModel(),
                    kermesseModel:          new KermesseModel(),
                    slotModel:              new SlotModel(),
                    profileDivergenceModel: new ProfileDivergenceModel(),
                ))->signup(
                    slotId:     (int) $slotId,
                    kermesseId: (int) $summary['kermesseId'],
                    fields:     $raw,
                );

                if (! $result->success) {
                    return view('kermesse/public/signup_form', [
                        'summary'         => $summary,
                        'fields'          => $raw,
                        'errors'          => ['_service' => $this->serviceErrorMessage($result)],
                        'isAuthenticated' => true,
                    ]);
                }

                session()->setFlashdata('signup_success', [
                    'slug'         => $publicSlug,
                    'slotId'       => (int) $slotId,
                    'kermesseName' => (string) $summary['kermesseName'],
                    'emailSent'    => $result->emailSent,
                ]);

                return redirect()->to(site_url("k/{$publicSlug}/slots/{$slotId}/signup/confirmation"));
            }
            // Stale session (user_id not in DB): fall through to anonymous flow.
        }

        $getPostString = function (string $key): string {
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
            return view('kermesse/public/signup_form', [
                'summary'         => $summary,
                'fields'          => $raw,
                'errors'          => $validation->getErrors(),
                'isAuthenticated' => false,
            ]);
        }

        $result = (new SignupService(
            userModel:              new UserModel(),
            signupModel:            new SignupModel(),
            kermesseModel:          new KermesseModel(),
            slotModel:              new SlotModel(),
            profileDivergenceModel: new ProfileDivergenceModel(),
        ))->signup(
            slotId:     (int) $slotId,
            kermesseId: (int) $summary['kermesseId'],
            fields:     $validation->getValidated(),
        );

        if (! $result->success) {
            return view('kermesse/public/signup_form', [
                'summary'         => $summary,
                'fields'          => $raw,
                'errors'          => ['_service' => $this->serviceErrorMessage($result)],
                'isAuthenticated' => false,
            ]);
        }

        session()->set('volunteer_identity', [
            'first_name' => $raw['first_name'],
            'last_name'  => $raw['last_name'],
            'email'      => $raw['email'],
        ]);

        session()->setFlashdata('signup_success', [
            'slug'         => $publicSlug,
            'slotId'       => (int) $slotId,
            'kermesseName' => (string) $summary['kermesseName'],
            'emailSent'    => $result->emailSent,
        ]);

        return redirect()->to(site_url("k/{$publicSlug}/slots/{$slotId}/signup/confirmation"));
    }

    public function confirm(string $publicSlug, string $slotId): mixed
    {
        $flash = session()->getFlashdata('signup_success');

        if (! is_array($flash)
            || ($flash['slug'] ?? null) !== $publicSlug
            || ($flash['slotId'] ?? null) !== (int) $slotId) {
            return redirect()->to(site_url("k/{$publicSlug}"));
        }

        return view('kermesse/public/signup_confirmation', [
            'kermesseName' => (string) ($flash['kermesseName'] ?? ''),
            'publicSlug'   => $publicSlug,
            'emailSent'    => $flash['emailSent'] ?? null,
        ]);
    }

    private function serviceErrorMessage(SignupResult $result): string
    {
        if ($result->errorCode === 'slot_full') {
            return 'Ce créneau vient d\'être rempli. Choisissez un autre créneau.';
        }

        if ($result->errorCode === 'duplicate_signup') {
            return 'Vous avez déjà une inscription active sur ce créneau avec cette adresse email.';
        }

        if ($result->errorCode === 'overlap_conflict') {
            $start   = $result->context['conflicting_starts_at'] ?? null;
            $end     = $result->context['conflicting_ends_at']   ?? null;
            $tsStart = $start ? strtotime((string) $start) : false;
            $tsEnd   = $end ? strtotime((string) $end) : false;
            $time    = ($tsStart !== false && $tsEnd !== false)
                ? ' (' . date('H:i', $tsStart) . '–' . date('H:i', $tsEnd) . ')'
                : '';
            return 'Vous avez déjà une inscription sur un créneau qui se chevauche' . $time . '.';
        }

        if ($result->errorCode === 'signups_not_open') {
            return 'Les inscriptions ne sont pas ouvertes pour cette kermesse.';
        }

        return 'Votre inscription n\'a pas pu être enregistrée. Veuillez réessayer.';
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
