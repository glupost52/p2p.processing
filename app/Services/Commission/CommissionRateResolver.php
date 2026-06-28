<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Contracts\CommissionRateResolverContract;
use App\Enums\CommissionOperationType;
use App\Models\Merchant;
use App\Models\PaymentGateway;
use App\Models\PaymentGatewayCommissionTier;
use App\Models\TraderCommissionRate;
use App\Models\User;
use App\Models\ValueObjects\Settings\PrimeTimeSettings;
use App\Services\Commission\Values\ResolvedCommissionRates;
use App\Services\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class CommissionRateResolver implements CommissionRateResolverContract
{
    public function resolve(
        PaymentGateway $paymentGateway,
        Money $amount,
        CommissionOperationType $operationType,
        ?Merchant $merchant = null,
        ?User $trader = null,
        ?PrimeTimeSettings $primeTime = null,
    ): ResolvedCommissionRates {
        $amountValue = $this->normalizeAmount($amount);
        $gatewayDefaults = $this->gatewayDefaultRates($paymentGateway, $operationType);
        $gatewayTier = $this->findGatewayTier($paymentGateway, $operationType, $amountValue);

        $totalServiceRate = $gatewayTier?->total_service_commission_rate ?? $gatewayDefaults['total'];
        $traderRate = $gatewayTier?->trader_commission_rate ?? $gatewayDefaults['trader'];

        if ($merchant !== null) {
            $totalServiceRate = $this->resolveMerchantTotalRate(
                merchant: $merchant,
                paymentGateway: $paymentGateway,
                amountValue: $amountValue,
                fallbackTotalRate: $totalServiceRate,
            );
        }

        if ($trader !== null) {
            $traderRate = $this->resolveTraderRate(
                trader: $trader,
                paymentGateway: $paymentGateway,
                operationType: $operationType,
                amountValue: $amountValue,
                fallbackTraderRate: $traderRate,
            );
        }

        $primeTimeBonus = $this->resolvePrimeTimeBonus($primeTime);

        if ($primeTimeBonus > 0) {
            $traderRate = round($traderRate + $primeTimeBonus, 2);
        }

        $this->assertRatesAreValid($totalServiceRate, $traderRate);

        return new ResolvedCommissionRates(
            totalServiceCommissionRate: $totalServiceRate,
            traderCommissionRate: $traderRate,
            primeTimeBonusRate: $primeTimeBonus,
        );
    }

    /**
     * @return array{trader: float, total: float}
     */
    private function gatewayDefaultRates(PaymentGateway $paymentGateway, CommissionOperationType $operationType): array
    {
        if ($operationType === CommissionOperationType::PAYOUT) {
            return [
                'trader' => (float) $paymentGateway->trader_commission_rate_for_payouts,
                'total' => (float) $paymentGateway->total_service_commission_rate_for_payouts,
            ];
        }

        return [
            'trader' => (float) $paymentGateway->trader_commission_rate_for_orders,
            'total' => (float) $paymentGateway->total_service_commission_rate_for_orders,
        ];
    }

    private function findGatewayTier(
        PaymentGateway $paymentGateway,
        CommissionOperationType $operationType,
        int $amountValue,
    ): ?PaymentGatewayCommissionTier {
        $tiers = $this->gatewayTiers($paymentGateway, $operationType);

        return $this->findMatchingTier($tiers, $amountValue);
    }

    /**
     * @param Collection<int, PaymentGatewayCommissionTier> $tiers
     */
    private function findMatchingTier(Collection $tiers, int $amountValue): ?PaymentGatewayCommissionTier
    {
        return $tiers
            ->filter(fn (PaymentGatewayCommissionTier $tier) => $amountValue >= $tier->min_amount
                && $amountValue <= $tier->max_amount)
            ->sortBy([
                ['sort_order', 'asc'],
                ['min_amount', 'asc'],
            ])
            ->first();
    }

    /**
     * @return Collection<int, PaymentGatewayCommissionTier>
     */
    private function gatewayTiers(
        PaymentGateway $paymentGateway,
        CommissionOperationType $operationType,
    ): Collection {
        if ($paymentGateway->relationLoaded('commissionTiers')) {
            return $paymentGateway->commissionTiers
                ->where('operation_type', $operationType)
                ->values();
        }

        return $paymentGateway->commissionTiers()
            ->where('operation_type', $operationType->value)
            ->orderBy('sort_order')
            ->orderBy('min_amount')
            ->get();
    }

    private function resolveMerchantTotalRate(
        Merchant $merchant,
        PaymentGateway $paymentGateway,
        int $amountValue,
        float $fallbackTotalRate,
    ): float {
        $settings = $merchant->gateway_settings[$paymentGateway->id] ?? null;

        if ($settings === null) {
            return $fallbackTotalRate;
        }

        $commissionMode = $settings['commission_mode'] ?? null;

        if ($commissionMode === 'tiered' && ! empty($settings['custom_gateway_commission_tiers'])) {
            $tierRate = $this->resolveMerchantTierTotalRate($settings['custom_gateway_commission_tiers'], $amountValue);

            if ($tierRate !== null) {
                return $tierRate;
            }
        }

        return $this->resolveMerchantFlatTotalRate($settings, $fallbackTotalRate);
    }

    /**
     * @param array<int, array<string, mixed>> $tiers
     */
    private function resolveMerchantTierTotalRate(array $tiers, int $amountValue): ?float
    {
        foreach ($tiers as $tier) {
            $minAmount = isset($tier['min_amount']) ? (int) $tier['min_amount'] : null;
            $maxAmount = isset($tier['max_amount']) ? (int) $tier['max_amount'] : null;

            if ($minAmount === null || $maxAmount === null) {
                continue;
            }

            if ($amountValue >= $minAmount && $amountValue <= $maxAmount) {
                return (float) ($tier['total_service_commission_rate'] ?? $tier['total'] ?? 0);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function resolveMerchantFlatTotalRate(array $settings, float $fallbackTotalRate): float
    {
        if (! array_key_exists('custom_gateway_commission', $settings)) {
            return $fallbackTotalRate;
        }

        $customCommission = $settings['custom_gateway_commission'];

        if ($customCommission !== null && $customCommission !== '' && (float) $customCommission > 0) {
            return (float) $customCommission;
        }

        if ((int) $customCommission === 0) {
            return 0.0;
        }

        return $fallbackTotalRate;
    }

    private function resolveTraderRate(
        User $trader,
        PaymentGateway $paymentGateway,
        CommissionOperationType $operationType,
        int $amountValue,
        float $fallbackTraderRate,
    ): float {
        $rates = $this->traderRates($trader, $paymentGateway, $operationType);

        if ($rates->isEmpty()) {
            return $fallbackTraderRate;
        }

        $tierRate = $this->findMatchingTraderTierRate($rates, $amountValue);

        if ($tierRate !== null) {
            return $tierRate;
        }

        $flatRate = $rates
            ->first(fn (TraderCommissionRate $rate) => $rate->isFlat());

        if ($flatRate !== null) {
            return (float) $flatRate->trader_commission_rate;
        }

        return $fallbackTraderRate;
    }

    /**
     * @return Collection<int, TraderCommissionRate>
     */
    private function traderRates(
        User $trader,
        PaymentGateway $paymentGateway,
        CommissionOperationType $operationType,
    ): Collection {
        if ($trader->relationLoaded('traderCommissionRates')) {
            return $trader->traderCommissionRates
                ->where('payment_gateway_id', $paymentGateway->id)
                ->where('operation_type', $operationType)
                ->where('is_active', true)
                ->values();
        }

        return $trader->traderCommissionRates()
            ->where('payment_gateway_id', $paymentGateway->id)
            ->where('operation_type', $operationType->value)
            ->where('is_active', true)
            ->get();
    }

    /**
     * @param Collection<int, TraderCommissionRate> $rates
     */
    private function findMatchingTraderTierRate(Collection $rates, int $amountValue): ?float
    {
        $tierRate = $rates
            ->filter(fn (TraderCommissionRate $rate) => ! $rate->isFlat()
                && $amountValue >= (int) $rate->min_amount
                && $amountValue <= (int) $rate->max_amount)
            ->sortBy('min_amount')
            ->first();

        if ($tierRate === null) {
            return null;
        }

        return (float) $tierRate->trader_commission_rate;
    }

    private function resolvePrimeTimeBonus(?PrimeTimeSettings $primeTime): float
    {
        if ($primeTime === null || $primeTime->rate <= 0) {
            return 0.0;
        }

        $start = Carbon::createFromTimeString($primeTime->starts);
        $end = Carbon::createFromTimeString($primeTime->ends);

        if (! now()->between($start, $end)) {
            return 0.0;
        }

        return $primeTime->rate;
    }

    private function normalizeAmount(Money $amount): int
    {
        return (int) $amount->toBeauty();
    }

    private function assertRatesAreValid(float $totalServiceRate, float $traderRate): void
    {
        if ($totalServiceRate < 0 || $traderRate < 0) {
            throw new \InvalidArgumentException('Commission rates cannot be negative.');
        }

        if ($totalServiceRate < $traderRate) {
            throw new \InvalidArgumentException('Trader commission rate cannot exceed total service commission rate.');
        }
    }
}
