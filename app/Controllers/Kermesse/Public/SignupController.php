<?php

namespace App\Controllers\Kermesse\Public;

use App\Controllers\BaseController;
use App\Models\KermesseModel;
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
            $user = model(UserModel::class)->find($authUserId);
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
            // Stale session (user_id not in DB): remove stale data and fall through to anonymous flow.
            session()->remove(['is_logged_in', 'user_id']);
            $isAuthenticated = false;
        }

        $identity = session()->get('volunteer_identity');
        $fields   = [
            'first_name' => (string) ($identity['first_name'] ?? ''),
            'last_name'  => (string) ($identity['last_name']  ?? ''),
            'email'      => (string) ($identity['email']      ?? ''),
            'phone'      => '',
        ];

        // Privacy (review 3.4): when the form is prefilled from a previous submission
        // cached in this session, expose a "Ce n'est pas vous ?" affordance so a visitor
        // on a shared device can wipe the previous person's name/email before signing up.
        $isPrefilled = is_array($identity) && (string) ($identity['email'] ?? '') !== '';

        return view('kermesse/public/signup_form', [
            'summary'         => $summary,
            'fields'          => $fields,
            'errors'          => [],
            'isAuthenticated' => false,
            'isPrefilled'     => $isPrefilled,
        ]);
    }

    /**
     * Clear the session-cached volunteer identity. PRG: redirects back to the empty
     * form. Privacy guard for shared devices (review 3.4) — the prefilled name/email
     * must not survive for the next visitor when they ask to forget it.
     */
    public function forget(string $publicSlug, string $slotId): mixed
    {
        session()->remove('volunteer_identity');

        return redirect()->to(site_url("k/{$publicSlug}/slots/{$slotId}/signup"));
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
            $user = model(UserModel::class)->find($authUserId);
            if ($user === null) {
                // Stale session (user_id not in DB): cannot fall through to anonymous flow safely
                // on POST without fields. Destroy stale data and redirect.
                session()->remove(['is_logged_in', 'user_id']);
                return redirect()->to(site_url("k/{$publicSlug}/slots/{$slotId}/signup"))
                    ->with('error', 'Votre session a expiré. Veuillez vous inscrire manuellement.');
            }

            $fields = [
                'first_name' => (string) $user['first_name'],
                'last_name'  => (string) $user['last_name'],
                'email'      => (string) $user['email'],
                'phone'      => (string) ($user['phone'] ?? ''),
            ];
            $rawForView = $fields;
        } else {
            $getPostString = function (string $key): string {
                $val = $this->request->getPost($key);
                return trim(is_array($val) ? '' : (string) $val);
            };

            $rawForView = [
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
            if (! $validation->setRules($rules)->run($rawForView)) {
                return view('kermesse/public/signup_form', [
                    'summary'         => $summary,
                    'fields'          => $rawForView,
                    'errors'          => $validation->getErrors(),
                    'isAuthenticated' => false,
                ]);
            }
            $fields = $validation->getValidated();
        }

        $result = $this->signupService()->signup(
            slotId:     (int) $slotId,
            kermesseId: (int) $summary['kermesseId'],
            fields:     $fields,
            createdBy:  $isAuthenticated ? $authUserId : null,
        );

        if (! $result->success) {
            return view('kermesse/public/signup_form', [
                'summary'         => $summary,
                'fields'          => $rawForView,
                'errors'          => ['_service' => $this->serviceErrorMessage($result)],
                'isAuthenticated' => $isAuthenticated,
            ]);
        }

        if (! $isAuthenticated) {
            session()->set('volunteer_identity', [
                'first_name' => $fields['first_name'],
                'last_name'  => $fields['last_name'],
                'email'      => $fields['email'],
            ]);
        }

        session()->setFlashdata('signup_success', [
            'slug'         => $publicSlug,
            'slotId'       => (int) $slotId,
            'kermesseName' => (string) $summary['kermesseName'],
            'standName'    => (string) $summary['standName'],
            'displayTime'  => (string) $summary['displayTime'],
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

        $authUserId      = (int) session()->get('user_id');
        $isAuthenticated = session()->get('is_logged_in') === true && $authUserId > 0;

        return view('kermesse/public/signup_confirmation', [
            'kermesseName'    => (string) ($flash['kermesseName'] ?? ''),
            'standName'       => (string) ($flash['standName'] ?? ''),
            'displayTime'     => (string) ($flash['displayTime'] ?? ''),
            'publicSlug'      => $publicSlug,
            'emailSent'       => $flash['emailSent'] ?? null,
            'isAuthenticated' => $isAuthenticated,
        ]);
    }

    /**
     * Build the SignupService with shared model instances. Extracted to a seam so a
     * test can subclass the controller and inject a mock service without touching HTTP.
     */
    protected function signupService(): SignupService
    {
        return new SignupService(
            userModel:              model(UserModel::class),
            signupModel:            model(SignupModel::class),
            kermesseModel:          model(KermesseModel::class),
            slotModel:              model(SlotModel::class),
        );
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
