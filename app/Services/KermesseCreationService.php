<?php

namespace App\Services;

use App\Models\OwnerModel;
use App\Models\KermesseModel;
use Config\Kermesse as KermesseConfig;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;

class KermesseCreationService
{
    private OwnerModel $ownerModel;
    private KermesseModel $kermesseModel;
    private TokenService $tokenService;
    private EmailService $emailService;
    private KermesseConfig $config;

    public function __construct(
        ?OwnerModel $ownerModel = null,
        ?KermesseModel $kermesseModel = null,
        ?TokenService $tokenService = null,
        ?EmailService $emailService = null,
        ?KermesseConfig $config = null,
    ) {
        $this->ownerModel    = $ownerModel ?? model(OwnerModel::class);
        $this->kermesseModel = $kermesseModel ?? model(KermesseModel::class);
        $this->tokenService  = $tokenService ?? new TokenService();
        $this->emailService  = $emailService ?? new EmailService();
        $this->config        = $config ?? config('Kermesse');
    }

    /**
     * Create a kermesse with a pending owner inside a DB transaction.
     *
     * Email sending happens AFTER the transaction commits so that
     * a SMTP failure does not roll back the owner/kermesse/token.
     *
     * @param array<string, string> $input
     */
    public function createWithPendingOwner(array $input): CreationResult
    {
        $email     = strtolower(trim($input['owner_email']));
        $emailHash = hash('sha256', $email);

        // Check for existing owner with same email hash
        $existingOwner = $this->ownerModel->where('email_hash', $emailHash)->first();
        if ($existingOwner !== null) {
            return new CreationResult(
                success: false,
                errorCode: 'duplicate_owner_email',
            );
        }

        /** @var BaseConnection $db */
        $db = \Config\Database::connect();
        $db->transException(true);

        try {
            $db->transBegin();

            // 1. Create owner
            try {
                $this->ownerModel->skipValidation(true);
                $ownerId = $this->ownerModel->insert([
                    'email'             => $email,
                    'email_hash'        => $emailHash,
                    'display_name'      => trim($input['owner_name']),
                    'status'            => 'owner_pending',
                    'email_verified_at' => null,
                ]);
                if ($ownerId === false) {
                    throw new \RuntimeException('Owner insert failed');
                }
            } catch (DatabaseException $e) {
                if ($this->isDuplicateOwnerEmailError($e)) {
                    $db->transRollback();

                    return new CreationResult(
                        success: false,
                        errorCode: 'duplicate_owner_email',
                    );
                }

                throw $e;
            } finally {
                $this->ownerModel->skipValidation(false);
            }

            // 2. Create kermesse — retry on public_slug collision (probability ~1/4 billion per attempt)
            $maxSlugAttempts = 3;
            $kermesseId      = false;

            for ($attempt = 1; $attempt <= $maxSlugAttempts; $attempt++) {
                $publicSlug = $this->generatePublicSlug($input['kermesse_name']);

                try {
                    $this->kermesseModel->skipValidation(true);
                    $kermesseId = $this->kermesseModel->insert([
                        'owner_id'          => $ownerId,
                        'public_slug'       => $publicSlug,
                        'name'              => trim($input['kermesse_name']),
                        'event_date'        => $input['event_date'],
                        'location'          => trim($input['location']),
                        'short_description' => trim($input['short_description'] ?? ''),
                        'timezone'          => 'Europe/Paris',
                        'status'            => 'preparation',
                    ]);
                    if ($kermesseId === false) {
                        throw new \RuntimeException('Kermesse insert failed');
                    }
                    break;
                } catch (DatabaseException $e) {
                    if ($this->isSlugCollisionError($e) && $attempt < $maxSlugAttempts) {
                        continue; // retry with a freshly generated slug
                    }
                    throw $e;
                } finally {
                    $this->kermesseModel->skipValidation(false);
                }
            }

            // 3. Issue validation token
            $issuedToken = $this->tokenService->issueOwnerValidationToken(
                (int) $ownerId,
                (int) $kermesseId,
                $email,
            );

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'KermesseCreationService: ' . $e->getMessage());

            return new CreationResult(
                success: false,
                errorCode: 'creation_failed',
            );
        }

        // 4. Build validation URL and send email (after commit)
        $baseUrl       = rtrim($this->config->publicBaseURL, '/');
        $validationUrl = $baseUrl . '/owner/validate/' . $issuedToken->rawToken;

        $emailResult = $this->emailService->sendOwnerValidationEmail(
            $email,
            trim($input['owner_name']),
            trim($input['kermesse_name']),
            $validationUrl,
        );

        // Note: validationUrl intentionally NOT propagated in the result object
        // to prevent the raw token from leaking beyond the service boundary.
        return new CreationResult(
            success: true,
            ownerId: (int) $ownerId,
            kermesseId: (int) $kermesseId,
            emailResult: $emailResult,
        );
    }

    /**
     * Generate a non-guessable public slug from the kermesse name.
     */
    private function generatePublicSlug(string $name): string
    {
        $base   = $this->slugify($name);
        $suffix = bin2hex(random_bytes(4)); // 8 hex chars

        // The suffix adds 9 chars ('-' + 8 hex). Cap the base to keep the
        // total within the VARCHAR(255) column limit.
        if (strlen($base) > 246) {
            $base = substr($base, 0, 246);
        }

        return $base . '-' . $suffix;
    }

    /**
     * Convert a string to a URL-safe slug.
     */
    private function slugify(string $text): string
    {
        $source = $text;

        // Transliterate accented chars — guarded against missing intl extension
        if (function_exists('transliterator_transliterate')) {
            $result = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
            $text   = is_string($result) ? $result : strtolower($source);
        } else {
            $text = strtolower($source);
        }

        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');

        return $text ?: 'kermesse';
    }

    private function isDuplicateOwnerEmailError(DatabaseException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'uq_owners_email_hash')
            || str_contains($message, 'duplicate')
            || str_contains($message, 'unique constraint');
    }

    private function isSlugCollisionError(DatabaseException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'uq_kermesses_slug')
            || (str_contains($message, 'public_slug') && str_contains($message, 'duplicate'));
    }
}
