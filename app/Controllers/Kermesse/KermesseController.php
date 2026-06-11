<?php

namespace App\Controllers\Kermesse;

use App\Controllers\BaseController;
use App\Models\KermesseModel;
use App\Models\UserModel;
use App\Models\UserRoleModel;
use App\Services\EmailService;
use App\Services\TokenService;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * Handles kermesse creation for both connected and guest users (Story 2.1).
 */
class KermesseController extends BaseController
{
    private const MAX_SLUG_ATTEMPTS = 5;

    /** GET /kermesse/create */
    public function create(): mixed
    {
        return view('kermesse/create', [
            'errors'     => [],
            'oldInput'   => [],
            'isLoggedIn' => (bool) session()->get('is_logged_in'),
        ]);
    }

    /** POST /kermesses */
    public function store(): mixed
    {
        $claimedLoggedIn = (bool) session()->get('is_logged_in');
        $userId          = (int) session()->get('user_id');
        $userModel       = model(UserModel::class);
        $isLoggedIn      = $claimedLoggedIn && $userId > 0 && $userModel->find($userId) !== null;

        if ($claimedLoggedIn && ! $isLoggedIn) {
            session()->remove(['user_id', 'is_logged_in']);
        }

        $rules = [
            'name'              => 'required|max_length[255]',
            'event_date'        => 'required|valid_date[Y-m-d]',
            'location'          => 'required|max_length[255]',
            'short_description' => 'permit_empty|max_length[500]',
        ];

        if (! $isLoggedIn) {
            $rules['first_name'] = 'required|max_length[100]';
            $rules['last_name']  = 'required|max_length[100]';
            $rules['email']      = 'required|valid_email|max_length[320]';
        }

        $post       = $this->scalarPost([
            'name',
            'event_date',
            'location',
            'short_description',
            'first_name',
            'last_name',
            'email',
        ]);
        $validation = service('validation');

        if (! $validation->setRules($rules)->run($post)) {
            return view('kermesse/create', [
                'errors'     => $validation->getErrors(),
                'oldInput'   => $post,
                'isLoggedIn' => $isLoggedIn,
            ]);
        }

        $name        = trim((string) ($post['name'] ?? ''));
        $eventDate   = (string) ($post['event_date'] ?? '');
        $location    = trim((string) ($post['location'] ?? ''));
        $description = trim((string) ($post['short_description'] ?? ''));

        $kermesseModel = model(KermesseModel::class);
        $roleModel     = model(UserRoleModel::class);
        $db            = \Config\Database::connect();

        if ($isLoggedIn && $userId > 0) {
            return $this->storeConnected($db, $kermesseModel, $roleModel, $userId, $name, $eventDate, $location, $description, $post);
        }

        return $this->storeGuest($db, $kermesseModel, $roleModel, $post, $name, $eventDate, $location, $description);
    }

    /** @param array<string, mixed> $post */
    private function storeConnected(
        \CodeIgniter\Database\ConnectionInterface $db,
        KermesseModel $kermesseModel,
        UserRoleModel $roleModel,
        int $userId,
        string $name,
        string $eventDate,
        string $location,
        string $description,
        array $post,
    ): mixed {
        $db->transStart();

        $kermesseId = $this->insertKermesseWithUniqueSlug($kermesseModel, [
            'created_by'        => $userId,
            'name'              => $name,
            'event_date'        => $eventDate ?: null,
            'location'          => $location,
            'short_description' => $description,
            'status'            => KermesseModel::STATUS_PREPARATION,
        ], $name);

        if ($kermesseId === false) {
            $db->transRollback();
            return view('kermesse/create', [
                'errors'     => ['_service' => 'Une erreur est survenue lors de la création. Veuillez réessayer.'],
                'oldInput'   => $post,
                'isLoggedIn' => true,
            ]);
        }

        if (! $this->assignOwnerRole($roleModel, (int) $kermesseId, $userId)) {
            $db->transRollback();
            return view('kermesse/create', [
                'errors'     => ['_service' => 'Une erreur est survenue lors de la création. Veuillez réessayer.'],
                'oldInput'   => $post,
                'isLoggedIn' => true,
            ]);
        }

        $db->transComplete();

        if (! $db->transStatus()) {
            return view('kermesse/create', [
                'errors'     => ['_service' => 'Une erreur est survenue lors de la création. Veuillez réessayer.'],
                'oldInput'   => $post,
                'isLoggedIn' => true,
            ]);
        }

        return redirect()->to(site_url('kermesse/' . (int) $kermesseId));
    }

    /** @param array<string, mixed> $post */
    private function storeGuest(
        \CodeIgniter\Database\ConnectionInterface $db,
        KermesseModel $kermesseModel,
        UserRoleModel $roleModel,
        array $post,
        string $name,
        string $eventDate,
        string $location,
        string $description,
    ): mixed {
        $emailRaw  = trim((string) ($post['email'] ?? ''));
        $email     = strtolower($emailRaw);
        $firstName = trim((string) ($post['first_name'] ?? ''));
        $lastName  = trim((string) ($post['last_name'] ?? ''));

        $userModel = model(UserModel::class);

        try {
            $db->transStart();

            $guestUserId = $userModel->findOrCreateWithProfile($email, $firstName, $lastName);

            if ($guestUserId === null) {
                $db->transRollback();
                return view('kermesse/create', [
                    'errors'     => ['_service' => 'Une erreur est survenue lors de la création. Veuillez réessayer.'],
                    'oldInput'   => $post,
                    'isLoggedIn' => false,
                ]);
            }

            $kermesseId = $this->insertKermesseWithUniqueSlug($kermesseModel, [
                'created_by'        => $guestUserId,
                'name'              => $name,
                'event_date'        => $eventDate ?: null,
                'location'          => $location,
                'short_description' => $description,
                'status'            => KermesseModel::STATUS_PREPARATION,
            ], $name);

            if ($kermesseId === false) {
                $db->transRollback();
                return view('kermesse/create', [
                    'errors'     => ['_service' => 'Une erreur est survenue lors de la création. Veuillez réessayer.'],
                    'oldInput'   => $post,
                    'isLoggedIn' => false,
                ]);
            }

            if (! $this->assignOwnerRole($roleModel, (int) $kermesseId, $guestUserId)) {
                $db->transRollback();
                return view('kermesse/create', [
                    'errors'     => ['_service' => 'Une erreur est survenue lors de la création. Veuillez réessayer.'],
                    'oldInput'   => $post,
                    'isLoggedIn' => false,
                ]);
            }

            $issued = (new TokenService())->issueMagicLink($email, (int) $kermesseId);

            $db->transComplete();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'KermesseController: guest creation failed: ' . $e->getMessage());
            return view('kermesse/create', [
                'errors'     => ['_service' => 'Une erreur est survenue lors de la création. Veuillez réessayer.'],
                'oldInput'   => $post,
                'isLoggedIn' => false,
            ]);
        }

        if (! $db->transStatus()) {
            return view('kermesse/create', [
                'errors'     => ['_service' => 'Une erreur est survenue lors de la création. Veuillez réessayer.'],
                'oldInput'   => $post,
                'isLoggedIn' => false,
            ]);
        }

        $loginUrl = site_url('auth/magic-link/' . $issued->rawToken);

        session()->set('redirect_url', site_url('kermesse/' . (int) $kermesseId));

        $delivery = (new EmailService())->sendUserLoginEmail($email, $loginUrl);
        if (! $delivery->sent) {
            log_message('warning', 'KermesseController: magic link email delivery failed for kermesse ' . (int) $kermesseId);
            return view('kermesse/create', [
                'errors'     => ['_service' => 'Le lien de connexion n’a pas pu être envoyé. Veuillez réessayer.'],
                'oldInput'   => $post,
                'isLoggedIn' => false,
            ]);
        }

        return view('auth/magic_link_sent', ['email' => $email]);
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    private function scalarPost(array $keys): array
    {
        $input = [];
        foreach ($keys as $key) {
            $value       = $this->request->getPost($key);
            $input[$key] = is_array($value) ? '' : (string) ($value ?? '');
        }

        return $input;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return int|false
     */
    private function insertKermesseWithUniqueSlug(KermesseModel $kermesseModel, array $data, string $name): int|false
    {
        for ($attempt = 0; $attempt < self::MAX_SLUG_ATTEMPTS; $attempt++) {
            $data['public_slug'] = KermesseModel::generateSlug($name);

            try {
                $kermesseModel->skipValidation(true);
                $kermesseId = $kermesseModel->insert($data);
            } catch (DatabaseException $e) {
                if ($this->isPublicSlugCollision($e)) {
                    continue;
                }

                throw $e;
            } finally {
                $kermesseModel->skipValidation(false);
            }

            if ($kermesseId !== false) {
                return (int) $kermesseId;
            }
        }

        return false;
    }

    private function assignOwnerRole(UserRoleModel $roleModel, int $kermesseId, int $userId): bool
    {
        try {
            $roleModel->skipValidation(true);
            return $roleModel->insert([
                'kermesse_id' => $kermesseId,
                'user_id'     => $userId,
                'role'        => UserRoleModel::ROLE_OWNER,
            ]) !== false;
        } finally {
            $roleModel->skipValidation(false);
        }
    }

    private function isPublicSlugCollision(DatabaseException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'public_slug')
            || str_contains($message, 'uq_kermesses_slug');
    }
}
