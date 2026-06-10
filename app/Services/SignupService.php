<?php

namespace App\Services;

use App\Models\KermesseModel;
use App\Models\SignupModel;
use App\Models\SlotModel;
use App\Models\VolunteerModel;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * Handles volunteer signup: validates kermesse state, slot capacity, duplicate and overlap
 * constraints, then finds or creates the volunteer and inserts the signup row.
 * All writes and constraint checks are wrapped in a single transaction.
 *
 * INVARIANT: email is normalized (lowercase + trim) before any DB lookup or insert.
 *
 * BOUNDARY: this service owns all signup state mutations and all business invariants.
 * Controllers must never call VolunteerModel or SignupModel insert/update directly.
 */
class SignupService
{
    public function __construct(
        private readonly VolunteerModel       $volunteerModel,
        private readonly SignupModel          $signupModel,
        private readonly ?KermesseModel       $kermesseModel = null,
        private readonly ?SlotModel           $slotModel = null,
        private readonly ?ConnectionInterface $db = null,
    ) {}

    /**
     * Validate all business invariants and, if satisfied, insert the signup.
     *
     * Failure codes: signups_not_open | slot_full | duplicate_signup | overlap_conflict
     *                volunteer_insert_failed | signup_insert_failed | transaction_failed
     *
     * @param array<string, mixed> $fields  Validated: first_name, last_name, email, phone
     */
    public function signup(int $slotId, int $kermesseId, array $fields): SignupResult
    {
        $email = mb_strtolower(trim((string) ($fields['email'] ?? '')), 'UTF-8');

        if ($email === '') {
            return SignupResult::failure('volunteer_insert_failed');
        }

        // Pre-transaction: kermesse must be open (defense-in-depth; form URL already guards this)
        $kermesse = ($this->kermesseModel ?? model(KermesseModel::class))->find($kermesseId);
        if ($kermesse === null || $kermesse['status'] !== 'open') {
            return SignupResult::failure('signups_not_open');
        }

        $db = $this->db ?? db_connect();
        $db->transStart();

        // Lock the slot row so concurrent transactions serialize on the capacity check
        $slot = ($this->slotModel ?? model(SlotModel::class))->findForCapacityCheck($slotId, $db);
        if ($slot === null) {
            $db->transRollback();
            return SignupResult::failure('slot_full');
        }

        $activeCount = $this->signupModel->countActiveForSlot($slotId);
        if ($activeCount >= (int) $slot['capacity']) {
            $db->transRollback();
            return SignupResult::failure('slot_full');
        }

        // Find or create the volunteer profile for this kermesse
        $volunteerId = null;
        $existing    = $this->volunteerModel->findByKermesseAndEmail($kermesseId, $email);

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
                // Race condition fallback: another request may have inserted the same volunteer
                $existing = $this->volunteerModel->findByKermesseAndEmail($kermesseId, $email);
                if ($existing !== null) {
                    $volunteerId = (int) $existing['id'];
                } else {
                    $db->transRollback();
                    return SignupResult::failure('volunteer_insert_failed');
                }
            }
        }

        // Lock the volunteer row to serialize concurrent overlap checks for the same person
        $this->volunteerModel->lockForOverlapCheck($volunteerId, $db);

        // Duplicate check: same volunteer already signed up for this slot
        if ($this->signupModel->findActiveByVolunteerAndSlot($volunteerId, $slotId) !== null) {
            $db->transRollback();
            return SignupResult::failure('duplicate_signup');
        }

        // Overlap check: same volunteer has an active signup on a slot with overlapping hours
        $overlap = $this->signupModel->findOverlappingActiveByVolunteer(
            $volunteerId,
            (string) $slot['starts_at'],
            (string) $slot['ends_at'],
            $slotId,
        );
        if ($overlap !== null) {
            $db->transRollback();
            return SignupResult::failure('overlap_conflict', [
                'conflicting_starts_at' => $overlap['starts_at'] ?? null,
                'conflicting_ends_at'   => $overlap['ends_at'] ?? null,
            ]);
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
