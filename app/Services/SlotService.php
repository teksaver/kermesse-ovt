<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SlotModel;
use App\Models\StandModel;
use App\Models\KermesseModel;

/**
 * Owns the slot creation invariant — Story 6.1.
 *
 * The controller orchestrates HTTP and validation; this service is the single
 * place that checks business pre-conditions and performs the DB write.
 * Stable result constants let the controller map outcomes to French messages
 * without leaking DB exceptions to the user.
 */
class SlotService
{
    public const RESULT_CREATED       = 'created';
    public const RESULT_NOT_FOUND     = 'not_found';
    public const RESULT_PERSIST_ERROR = 'persist_error';

    /**
     * Create an active slot after verifying the kermesse, stand ownership, and
     * stand status. Returns one of the RESULT_* constants; never throws.
     */
    public function create(CreateSlotDTO $dto): string
    {
        $db = db_connect();
        try {
            $kermesse = model(KermesseModel::class)->find($dto->kermesseId);
            if ($kermesse === null) {
                return self::RESULT_NOT_FOUND;
            }

            // Transaction makes the stand re-validation and the insert atomic.
            $db->transBegin();
            try {
                $stand = model(StandModel::class)
                    ->where('kermesse_id', $dto->kermesseId)
                    ->where('status', StandModel::STATUS_ACTIVE)
                    ->find($dto->standId);

                if ($stand === null) {
                    $db->transRollback();
                    return self::RESULT_NOT_FOUND;
                }

                $inserted = model(SlotModel::class)->insert([
                    'stand_id'  => $dto->standId,
                    'starts_at' => $dto->startsAt,
                    'ends_at'   => $dto->endsAt,
                    'capacity'  => $dto->capacity,
                    'status'    => SlotModel::STATUS_ACTIVE,
                ]);

                if (! $inserted) {
                    $db->transRollback();
                    return self::RESULT_PERSIST_ERROR;
                }

                $db->transCommit();
                return self::RESULT_CREATED;
            } catch (\Throwable $inner) {
                $db->transRollback();
                throw $inner;
            }
        } catch (\Throwable $e) {
            log_message('error', '[SlotService::create] ' . $e->getMessage());

            return self::RESULT_PERSIST_ERROR;
        }
    }
}
