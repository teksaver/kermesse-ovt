<?php

declare(strict_types=1);

namespace App\Entities;

use CodeIgniter\Entity\Entity;

/**
 * SlotSignup entity — owns the state machine for timestamp-based status calculation.
 *
 * computeStatus() is the canonical source of truth for slot-signup state. All controllers,
 * services, and tests must derive status through this class; never inline the conditions.
 * SlotSignupModel::getStatus() delegates here for backward-compatible static calls.
 */
class SlotSignup extends Entity
{
    /**
     * Compute slot-signup status from this entity's own timestamps.
     */
    public function getStatus(): string
    {
        return self::computeStatus([
            'deleted_at'  => $this->deleted_at,
            'canceled_at' => $this->canceled_at,
            'canceled_by' => $this->canceled_by,
            'user_id'     => $this->user_id,
            'rejected_at' => $this->rejected_at,
            'accepted_at' => $this->accepted_at,
            'created_by'  => $this->created_by,
        ]);
    }

    /**
     * Compute slot-signup status from a raw row array (static — usable without an instance).
     *
     * Priority (highest to lowest):
     *   soft-deleted       → 'deactivated'  (system removal via stand/slot deletion)
     *   canceled_at set    → 'cancelled'    (by volunteer) or 'removed' (by admin)
     *   rejected_at set    → 'refused'
     *   accepted_at set    → 'certified'
     *   unconfirmed cond.  → 'unconfirmed'  (visitor or created by someone else)
     *   default            → 'active'
     */
    public static function computeStatus(array $row): string
    {
        if (! empty($row['deleted_at'])) {
            return 'deactivated';
        }
        if (! empty($row['canceled_at'])) {
            $canceledBy = isset($row['canceled_by']) ? (int) $row['canceled_by'] : null;
            $userId     = isset($row['user_id'])     ? (int) $row['user_id']     : null;
            return ($canceledBy !== null && $userId !== null && $canceledBy === $userId)
                ? 'cancelled'
                : 'removed';
        }
        if (! empty($row['rejected_at'])) {
            return 'refused';
        }
        if (! empty($row['accepted_at'])) {
            return 'certified';
        }
        $createdBy = isset($row['created_by']) ? (int) $row['created_by'] : null;
        $userId    = isset($row['user_id'])    ? (int) $row['user_id']    : null;
        if ($createdBy === null || ($userId !== null && $createdBy !== $userId)) {
            return 'unconfirmed';
        }
        return 'active';
    }
}
