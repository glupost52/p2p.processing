<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Enums\CommissionOperationType;
use App\Models\TraderCommissionRate;
use App\Models\User;
use App\Services\Commission\Exceptions\CommissionTierException;
use Illuminate\Support\Facades\DB;

class TraderCommissionRateService
{
    public function __construct(
        protected CommissionTierService $commissionTierService,
    ) {}

    /**
     * @param array<int, array<string, mixed>> $rates
     * @return array<int, TraderCommissionRate>
     */
    public function syncForUser(User $user, array $rates): array
    {
        $normalizedRates = $this->normalizeRates($rates);
        $this->assertRatesAreValid($normalizedRates);

        return DB::transaction(function () use ($user, $normalizedRates) {
            $user->traderCommissionRates()->delete();

            $created = [];

            foreach ($normalizedRates as $rate) {
                $created[] = $user->traderCommissionRates()->create([
                    'payment_gateway_id' => $rate['payment_gateway_id'],
                    'operation_type' => $rate['operation_type'],
                    'min_amount' => $rate['min_amount'],
                    'max_amount' => $rate['max_amount'],
                    'trader_commission_rate' => $rate['trader_commission_rate'],
                    'is_active' => $rate['is_active'],
                ]);
            }

            return $created;
        });
    }

    /**
     * @param array<int, array<string, mixed>> $rates
     * @return array<int, array<string, mixed>>
     */
    public function normalizeRates(array $rates): array
    {
        return collect($rates)
            ->map(function (array $rate) {
                $operationType = $rate['operation_type'] ?? CommissionOperationType::ORDER->value;
                $minAmount = array_key_exists('min_amount', $rate) && $rate['min_amount'] !== null
                    ? (int) $rate['min_amount']
                    : null;
                $maxAmount = array_key_exists('max_amount', $rate) && $rate['max_amount'] !== null
                    ? (int) $rate['max_amount']
                    : null;

                return [
                    'payment_gateway_id' => (int) ($rate['payment_gateway_id'] ?? 0),
                    'operation_type' => $operationType instanceof CommissionOperationType
                        ? $operationType->value
                        : (string) $operationType,
                    'min_amount' => $minAmount,
                    'max_amount' => $maxAmount,
                    'trader_commission_rate' => (float) ($rate['trader_commission_rate'] ?? 0),
                    'is_active' => array_key_exists('is_active', $rate) ? (bool) $rate['is_active'] : true,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $rates
     */
    public function assertRatesAreValid(array $rates): void
    {
        $groupedRates = collect($rates)
            ->groupBy(fn (array $rate) => $rate['payment_gateway_id'].'|'.$rate['operation_type']);

        foreach ($groupedRates as $group) {
            $tierRates = $group
                ->filter(fn (array $rate) => $rate['min_amount'] !== null && $rate['max_amount'] !== null)
                ->map(fn (array $rate) => [
                    'min_amount' => $rate['min_amount'],
                    'max_amount' => $rate['max_amount'],
                    'trader_commission_rate' => $rate['trader_commission_rate'],
                    'total_service_commission_rate' => $rate['trader_commission_rate'],
                ])
                ->values()
                ->all();

            if ($tierRates !== []) {
                $this->commissionTierService->assertTiersAreValid($tierRates);
            }

            $flatRates = $group->filter(fn (array $rate) => $rate['min_amount'] === null && $rate['max_amount'] === null);

            if ($flatRates->count() > 1) {
                throw new CommissionTierException('Only one flat trader commission rate is allowed per payment gateway and operation type.');
            }
        }
    }
}
