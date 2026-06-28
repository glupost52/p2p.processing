<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Enums\CommissionOperationType;
use App\Models\PaymentGateway;
use App\Models\PaymentGatewayCommissionTier;
use App\Services\Commission\Exceptions\CommissionTierException;
use Illuminate\Support\Facades\DB;

class CommissionTierService
{
    /**
     * @param array<int, array<string, mixed>> $tiers
     * @return array<int, PaymentGatewayCommissionTier>
     */
    public function syncForGateway(PaymentGateway $paymentGateway, CommissionOperationType $operationType, array $tiers): array
    {
        $normalizedTiers = $this->normalizeTiers($tiers);
        $this->assertTiersAreValid($normalizedTiers);

        return DB::transaction(function () use ($paymentGateway, $operationType, $normalizedTiers) {
            $paymentGateway->commissionTiers()
                ->where('operation_type', $operationType->value)
                ->delete();

            $created = [];

            foreach ($normalizedTiers as $index => $tier) {
                $created[] = $paymentGateway->commissionTiers()->create([
                    'operation_type' => $operationType->value,
                    'min_amount' => $tier['min_amount'],
                    'max_amount' => $tier['max_amount'],
                    'trader_commission_rate' => $tier['trader_commission_rate'],
                    'total_service_commission_rate' => $tier['total_service_commission_rate'],
                    'sort_order' => $tier['sort_order'] ?? $index,
                ]);
            }

            return $created;
        });
    }

    /**
     * @param array<int, array<string, mixed>> $tiers
     * @return array<int, array<string, mixed>>
     */
    public function normalizeTiers(array $tiers): array
    {
        return collect($tiers)
            ->map(function (array $tier, int $index) {
                return [
                    'min_amount' => (int) ($tier['min_amount'] ?? 0),
                    'max_amount' => (int) ($tier['max_amount'] ?? 0),
                    'trader_commission_rate' => (float) ($tier['trader_commission_rate'] ?? 0),
                    'total_service_commission_rate' => (float) ($tier['total_service_commission_rate'] ?? 0),
                    'sort_order' => isset($tier['sort_order']) ? (int) $tier['sort_order'] : $index,
                ];
            })
            ->sortBy([
                ['sort_order', 'asc'],
                ['min_amount', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $tiers
     */
    public function assertTiersAreValid(array $tiers): void
    {
        $sortedTiers = collect($tiers)->sortBy('min_amount')->values();

        foreach ($sortedTiers as $tier) {
            $minAmount = $tier['min_amount'];
            $maxAmount = $tier['max_amount'];
            $traderRate = $tier['trader_commission_rate'];
            $totalRate = $tier['total_service_commission_rate'];

            if ($minAmount > $maxAmount) {
                throw CommissionTierException::invalidRange($minAmount, $maxAmount);
            }

            if ($traderRate > $totalRate) {
                throw CommissionTierException::invalidRateRelation($traderRate, $totalRate);
            }
        }

        for ($index = 0; $index < $sortedTiers->count() - 1; $index++) {
            $currentTier = $sortedTiers[$index];
            $nextTier = $sortedTiers[$index + 1];

            if ($nextTier['min_amount'] <= $currentTier['max_amount']) {
                throw CommissionTierException::overlappingRanges(
                    $currentTier['min_amount'],
                    $currentTier['max_amount'],
                    $nextTier['min_amount'],
                    $nextTier['max_amount'],
                );
            }
        }
    }
}
