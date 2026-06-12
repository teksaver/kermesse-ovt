<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProfileDivergenceModel;
use App\Models\UserModel;

/**
 * Owns profile divergence resolution: updates the user record and marks
 * all pending divergences as resolved in a single transaction.
 * Story 3.6.
 */
class ProfileService
{
    public function __construct(
        private readonly UserModel $userModel,
        private readonly ProfileDivergenceModel $profileDivergenceModel,
    ) {}

    /**
     * Resolve all unresolved profile divergences for a user.
     *
     * $choice = 'submitted' → update user profile with the most-recent divergence values.
     * $choice = 'keep'      → leave user profile unchanged.
     * In both cases, every unresolved divergence row is stamped resolved_at.
     *
     * Returns true on success, false if the transaction could not commit.
     */
    public function resolveProfileDivergences(int $userId, string $choice): bool
    {
        $divergences = $this->profileDivergenceModel->findUnresolvedByUser($userId);

        if (empty($divergences)) {
            return true;
        }

        $db = db_connect();
        $db->transStart();

        if ($choice === 'submitted') {
            // findUnresolvedByUser orders DESC — first row is the most recent.
            $latest  = $divergences[0];
            $updated = $this->userModel->skipValidation(true)->update($userId, [
                'first_name' => $latest['submitted_first_name'],
                'last_name'  => $latest['submitted_last_name'],
                'phone'      => $latest['submitted_phone'],
            ]);
            if ($updated === false) {
                $db->transRollback();
                return false;
            }
        }

        // Use the model to mark divergences resolved so timestamps are managed properly.
        $ids = array_column($divergences, 'id');
        $this->profileDivergenceModel
            ->skipValidation(true)
            ->whereIn('id', $ids)
            ->update(null, ['resolved_at' => date('Y-m-d H:i:s')]);

        $db->transComplete();

        return $db->transStatus();
    }
}
