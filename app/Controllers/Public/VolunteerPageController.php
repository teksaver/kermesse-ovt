<?php

namespace App\Controllers\Public;

use App\Controllers\BaseController;
use App\Services\PublicVolunteerPageService;

/**
 * Public volunteer page: GET /k/{public_slug}
 *
 * This is the only public-facing volunteer surface. It renders event, stand,
 * slot and availability data only. It MUST NOT expose volunteer names, emails,
 * phone numbers, admin data, owner data, or management links — that boundary is
 * enforced by PublicVolunteerPageService, which whitelists the view model.
 */
class VolunteerPageController extends BaseController
{
    public function index(string $publicSlug): mixed
    {
        $viewModel = (new PublicVolunteerPageService())->buildForSlug($publicSlug);

        if ($viewModel === null) {
            // Neutral 404 — never reveal whether a slug exists or why.
            return service('response')
                ->setStatusCode(404)
                ->setBody(view('errors/html/error_404', [
                    'message' => 'Cette page n\'existe pas ou n\'est plus disponible.',
                ]));
        }

        return view('public/volunteer_page', $viewModel);
    }
}
