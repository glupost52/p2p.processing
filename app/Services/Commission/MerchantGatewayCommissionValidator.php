<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Enums\CommissionOperationType;
use App\Models\PaymentGateway;
use App\Services\Commission\Exceptions\CommissionTierException;

class MerchantGatewayCommissionValidator
{
    /**
     * @param array<int|string, array<string, mixed>> $gatewaySettings
     */
    public function validateGatewaySettings(array $gatewaySettings): void
    {
        foreach ($gatewaySettings as $gatewayId => $settings) {
            if (! is_numeric($gatewayId) || ! is_array($settings)) {
                continue;
            }

            $gateway = PaymentGateway::query()
                ->with([
                    'commissionTiers' => fn ($query) => $query->where('operation_type', CommissionOperationType::ORDER->value),
                ])
                ->find((int) $gatewayId);

            if ($gateway === null) {
                continue;
            }

            $this->validateSingleGatewaySettings($gateway, $settings);
        }
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function validateSingleGatewaySettings(PaymentGateway $gateway, array $settings): void
    {
        $commissionMode = $settings['commission_mode'] ?? 'inherit';

        if ($commissionMode === 'flat' || array_key_exists('custom_gateway_commission', $settings)) {
            $this->validateFlatTotal($gateway, $settings, $commissionMode);
        }

        if ($commissionMode === 'tiered') {
            $tiers = $settings['custom_gateway_commission_tiers'] ?? [];

            if ($tiers !== []) {
                $this->validateMerchantTiers($gateway, $tiers);
            }
        }
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function validateFlatTotal(PaymentGateway $gateway, array $settings, string $commissionMode): void
    {
        if ($commissionMode !== 'flat' && ! array_key_exists('custom_gateway_commission', $settings)) {
            return;
        }

        if (! array_key_exists('custom_gateway_commission', $settings)) {
            return;
        }

        $totalRate = (float) $settings['custom_gateway_commission'];

        if ($totalRate <= 0) {
            return;
        }

        $maxTraderRate = $this->maxGatewayTraderRate($gateway);

        if ($totalRate < $maxTraderRate) {
            throw CommissionTierException::invalidRateRelation($maxTraderRate, $totalRate);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $tiers
     */
    private function validateMerchantTiers(PaymentGateway $gateway, array $tiers): void
    {
        $normalizedTiers = collect($tiers)
            ->map(function (array $tier, int $index) {
                return [
                    'min_amount' => (int) ($tier['min_amount'] ?? 0),
                    'max_amount' => (int) ($tier['max_amount'] ?? 0),
                    'total_service_commission_rate' => (float) ($tier['total_service_commission_rate'] ?? 0),
                    'sort_order' => $index,
                ];
            })
            ->sortBy([
                ['sort_order', 'asc'],
                ['min_amount', 'asc'],
            ])
            ->values()
            ->all();

        foreach ($normalizedTiers as $tier) {
            if ($tier['min_amount'] > $tier['max_amount']) {
                throw CommissionTierException::invalidRange($tier['min_amount'], $tier['max_amount']);
            }

            $totalRate = $tier['total_service_commission_rate'];

            if ($totalRate <= 0) {
                continue;
            }

            $maxTraderRate = $this->maxGatewayTraderRateInRange(
                gateway: $gateway,
                minAmount: $tier['min_amount'],
                maxAmount: $tier['max_amount'],
            );

            if ($totalRate < $maxTraderRate) {
                throw CommissionTierException::invalidRateRelation($maxTraderRate, $totalRate);
            }
        }

        for ($index = 0; $index < count($normalizedTiers) - 1; $index++) {
            $currentTier = $normalizedTiers[$index];
            $nextTier = $normalizedTiers[$index + 1];

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

    private function maxGatewayTraderRate(PaymentGateway $gateway): float
    {
        $maxTraderRate = (float) $gateway->trader_commission_rate_for_orders;

        foreach ($gateway->commissionTiers as $tier) {
            $maxTraderRate = max($maxTraderRate, (float) $tier->trader_commission_rate);
        }

        return $maxTraderRate;
    }

    private function maxGatewayTraderRateInRange(PaymentGateway $gateway, int $minAmount, int $maxAmount): float
    {
        $maxTraderRate = (float) $gateway->trader_commission_rate_for_orders;

        foreach ($gateway->commissionTiers as $tier) {
            if ($tier->min_amount <= $maxAmount && $tier->max_amount >= $minAmount) {
                $maxTraderRate = max($maxTraderRate, (float) $tier->trader_commission_rate);
            }
        }

        return $maxTraderRate;
    }
}
