<?php

namespace App\Services;

use App\Models\SignupModel;
use App\Models\VolunteerModel;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * Handles volunteer signup: find or create the volunteer (scoped to kermesse),
 * then insert the signup row. Both writes are wrapped in a transaction.
 *
 * INVARIANT: email is normalized (lowercase + trim) before any DB lookup or insert
 * so that casing variants map to the same identity.
 *
 * BOUNDARY: this service owns all signup state mutations. Controllers must never
 * call VolunteerModel or SignupModel insert/update directly.
 */
class SignupService
{
    public function __construct(
        private readonly VolunteerModel $volunteerModel,
        private readonly SignupModel    $signupModel,
        private readonly ?ConnectionInterface $db = null,
    ) {}

    /**
     * Create or reuse the volunteer profile for this kermesse, then insert the signup.
     *
     * @param array<string, mixed> $fields  Validated: first_name, last_name, email, phone
     */
    public function signup(int $slotId, int $kermesseId, array $fields): SignupResult
    {
        $email = mb_strtolower(trim((string) ($fields['email'] ?? '')), 'UTF-8');

        $db = $this->db ?? db_connect();
        $db->transStart();

        $volunteerId = null;
        $existing = $this->volunteerModel->findByKermesseAndEmail($kermesseId, $email);

        if ($existing !== null) {
            $volunteerId = (int) $existing['id'];
        } else {
            try {
                $inserted = $this->volunteerModel->skipValidation(true)->insert([
                    'kermesse_id' => $kermesseId,
                    'first_name'  => (string) ($fields['first_name'] ?? ''),
                    'last_name'   => (string) ($fields['last_name'] ?? ''),
                    'email'       => $email,
                    'phone'       => (string) ($fields['phone'] ?? ''),
                ]);
                
                if ($inserted !== false) {
                    $volunteerId = (int) $inserted;
                }
            } catch (DatabaseException $e) {
                // Handled in fallback below
            }

            if ($volunteerId === null) {
                // Race condition fallback
                $existing = $this->volunteerModel->findByKermesseAndEmail($kermesseId, $email);
                if ($existing !== null) {
                    $volunteerId = (int) $existing['id'];
                } else {
                    $db->transRollback();
                    return SignupResult::failure('volunteer_insert_failed');
                }
            }
        }

        $signupId = $this->signupModel->skipValidation(true)->insert([
            'slot_id'      => $slotId,
            'volunteer_id' => $volunteerId,
            'status'       => 'active',
        ]);
        
        if ($signupId === false) {
            $db->transRollback();
            return SignupResult::failure('signup_insert_failed');
        }

        $db->transComplete();

        if (! $db->transStatus()) {
            return SignupResult::failure('transaction_failed');
        }

        return SignupResult::success((int) $signupId, $volunteerId);
    }
}
