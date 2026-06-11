<?php

namespace App\Controllers\Kermesse\Public;

use App\Controllers\BaseController;
use App\Services\PublicVolunteerPageService;
use App\Models\KermesseModel;

/**
 * Public volunteer page: GET /k/{public_slug}
 *
 * Privacy boundary: renders event, stand, slot, and availability data only.
 * Must never expose volunteer names, emails, phone numbers, admin data, or Magic Links.
 * Enforced by PublicVolunteerPageService whitelisting the view model.
 */
class PublicController extends BaseController
{
    public function index(string $publicSlug): mixed
    {
        $viewModel = (new PublicVolunteerPageService())->buildForSlug($publicSlug);

        if ($viewModel === null) {
            return service('response')
                ->setStatusCode(404)
                ->setBody(view('errors/html/error_404', [
                    'message' => 'Cette page n\'existe pas ou n\'est plus disponible.',
                ]));
        }

        return view('kermesse/public/volunteer_page', $viewModel);
    }
}
