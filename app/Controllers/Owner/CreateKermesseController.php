<?php

namespace App\Controllers\Owner;

use App\Controllers\BaseController;
use App\Services\KermesseCreationService;

class CreateKermesseController extends BaseController
{
    /**
     * Display the kermesse creation form.
     */
    public function showCreateForm(): string
    {
        helper('form');

        return view('owner/create', [
            'errors'   => [],
            'oldInput' => [],
        ]);
    }

    /**
     * Handle kermesse creation submission.
     */
    public function create()
    {
        helper('form');

        $input = $this->normaliseInput($this->request->getPost());

        $rules = [
            'owner_name'        => 'required|max_length[255]',
            'owner_email'       => 'required|valid_email|max_length[320]',
            'kermesse_name'     => 'required|max_length[255]',
            'event_date'        => 'required|valid_date[Y-m-d]',
            'location'          => 'required|max_length[255]',
            'short_description' => 'required|max_length[500]',
        ];

        $messages = [
            'owner_name' => [
                'required'   => 'Veuillez saisir votre nom.',
                'max_length' => 'Le nom ne doit pas dépasser 255 caractères.',
            ],
            'owner_email' => [
                'required'    => 'Veuillez saisir votre adresse email.',
                'valid_email' => 'Veuillez saisir une adresse email valide.',
                'max_length'  => 'L\'adresse email est trop longue.',
            ],
            'kermesse_name' => [
                'required'   => 'Veuillez donner un nom à votre kermesse.',
                'max_length' => 'Le nom de la kermesse ne doit pas dépasser 255 caractères.',
            ],
            'event_date' => [
                'required'   => 'Veuillez choisir une date pour l\'événement.',
                'valid_date' => 'La date doit être au format AAAA-MM-JJ.',
            ],
            'location' => [
                'required'   => 'Veuillez indiquer le lieu de la kermesse.',
                'max_length' => 'Le lieu ne doit pas dépasser 255 caractères.',
            ],
            'short_description' => [
                'required'   => 'Veuillez ajouter une description courte.',
                'max_length' => 'La description ne doit pas dépasser 500 caractères.',
            ],
        ];

        $validation = \Config\Services::validation();
        $validation->setRules($rules, $messages);

        if (! $validation->run($input)) {
            return view('owner/create', [
                'errors'   => $validation->getErrors(),
                'oldInput' => $input,
            ]);
        }

        $service = new KermesseCreationService();
        $result  = $service->createWithPendingOwner($input);

        if (! $result->success) {
            $errorMessages = [];

            if ($result->errorCode === 'duplicate_owner_email') {
                $errorMessages['owner_email'] = 'Cette adresse email est déjà utilisée pour une kermesse.';
            } else {
                $errorMessages['general'] = 'Une erreur est survenue lors de la création. Veuillez réessayer.';
            }

            return view('owner/create', [
                'errors'   => $errorMessages,
                'oldInput' => $input,
            ]);
        }

        return view('owner/email_check', [
            'kermesseName' => $input['kermesse_name'],
            'ownerEmail'   => $input['owner_email'],
            'emailSent'    => $result->emailResult?->sent ?? false,
        ]);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, string>
     */
    private function normaliseInput(array $input): array
    {
        $fields = [
            'owner_name',
            'owner_email',
            'kermesse_name',
            'event_date',
            'location',
            'short_description',
        ];

        $normalised = [];
        foreach ($fields as $field) {
            $value = $input[$field] ?? '';
            $normalised[$field] = is_scalar($value) ? trim((string) $value) : '';
        }

        return $normalised;
    }
}
