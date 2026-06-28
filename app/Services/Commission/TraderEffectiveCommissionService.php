<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Contracts\CommissionRateResolverContract;
use App\Contracts\TraderEffectiveCommissionServiceContract;
use App\Enums\CommissionOperationType;
use App\Models\PaymentDetail;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Services\Money\Money;
use Illuminate\Support\Collection;

class TraderEffectiveCommissionService implements TraderEffectiveCommissionServiceContract
{
    public function __construct(
        private readonly CommissionRateResolverContract $commissionRateResolver,
    ) {
    }

    public function buildForUser(User $trader): array
    {
        $trader->loadMissing([
            'traderCommissionRates' => function ($query) {
                $query->where('is_active', true)
                    ->orderBy('payment_gateway_id')
                    ->orderBy('operation_type')
                    ->orderBy('min_amount');
            },
        ]);

        $gatewayIds = $this->resolveGatewayIds($trader);

        if ($gatewayIds === []) {
            return [];
        }

        $gateways = PaymentGateway::query()
            ->with(['commissionTiers'])
            ->whereIn('id', $gatewayIds)
            ->where('is_active', 1)
            ->orderBy('name')
            ->get();

        $rows = [];

        foreach ($gateways as $gateway) {
            foreach (CommissionOperationType::cases() as $operationType) {
                if ($operationType === CommissionOperationType::PAYOUT && ! $gateway->is_payouts_enabled) {
                    continue;
                }

                $rows = array_merge(
                    $rows,
                    $this->buildRowsForGateway($trader, $gateway, $operationType),
                );
            }
        }

        return $rows;
    }

    /**
     * @return array<int, int>
     */
    private function resolveGatewayIds(User $trader): array
    {
        $fromDetails = PaymentDetail::query()
            ->where('user_id', $trader->id)
            ->whereNull('archived_at')
            ->whereHas('paymentGateways', fn ($query) => $query->where('is_active', 1))
            ->with('paymentGateways:id')
            ->get()
            ->flatMap(fn (PaymentDetail $detail) => $detail->paymentGateways->pluck('id'));

        $fromRates = $trader->traderCommissionRates->pluck('payment_gateway_id');

        return $fromDetails
            ->merge($fromRates)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildRowsForGateway(
        User $trader,
        PaymentGateway $gateway,
        CommissionOperationType $operationType,
    ): array {
        $currencyCode = $gateway->currency->getCode();

        $traderRates = $trader->traderCommissionRates
            ->where('payment_gateway_id', $gateway->id)
            ->where('operation_type', $operationType)
            ->values();

        $gatewayTiers = $gateway->commissionTiers
            ->where('operation_type', $operationType)
            ->sortBy([
                ['sort_order', 'asc'],
                ['min_amount', 'asc'],
            ])
            ->values();

        $segments = $this->collectSegments($gatewayTiers, $traderRates);
        $hasGatewayTiers = $gatewayTiers->isNotEmpty();
        $hasTraderTierRates = $traderRates->contains(fn ($rate) => ! $rate->isFlat());
        $rows = [];

        foreach ($segments as $segment) {
            $amount = $this->representativeAmountForSegment($segment, $gateway);
            $baseRate = $this->resolveBaseRate($gateway, $operationType, $amount, $currencyCode);
            $effectiveRate = $this->resolveEffectiveRate($trader, $gateway, $operationType, $amount, $currencyCode);
            $source = $this->resolveSource($effectiveRate, $baseRate, $hasGatewayTiers, $hasTraderTierRates);

            $rows[] = $this->makeRow(
                gateway: $gateway,
                operationType: $operationType,
                minAmount: $segment['min_amount'],
                maxAmount: $segment['max_amount'],
                effectiveRate: $effectiveRate,
                baseRate: $baseRate,
                source: $source,
            );
        }

        return $rows;
    }

    /**
     * @param Collection<int, \App\Models\PaymentGatewayCommissionTier> $gatewayTiers
     * @param Collection<int, \App\Models\TraderCommissionRate> $traderRates
     * @return array<int, array{min_amount: int|null, max_amount: int|null}>
     */
    private function collectSegments(Collection $gatewayTiers, Collection $traderRates): array
    {
        $traderTierRates = $traderRates->filter(fn ($rate) => ! $rate->isFlat());

        if ($traderTierRates->isNotEmpty()) {
            return $traderTierRates
                ->map(fn ($rate) => [
                    'min_amount' => $rate->min_amount,
                    'max_amount' => $rate->max_amount,
                ])
                ->values()
                ->all();
        }

        if ($gatewayTiers->isNotEmpty()) {
            return $gatewayTiers
                ->map(fn ($tier) => [
                    'min_amount' => $tier->min_amount,
                    'max_amount' => $tier->max_amount,
                ])
                ->values()
                ->all();
        }

        return [
            ['min_amount' => null, 'max_amount' => null],
        ];
    }

    /**
     * @param array{min_amount: int|null, max_amount: int|null} $segment
     */
    private function representativeAmountForSegment(array $segment, PaymentGateway $gateway): int
    {
        if ($segment['min_amount'] !== null && $segment['max_amount'] !== null) {
            return $this->representativeAmount($segment['min_amount'], $segment['max_amount']);
        }

        return max(1, (int) $gateway->min_limit);
    }

    private function resolveSource(
        float $effectiveRate,
        float $baseRate,
        bool $hasGatewayTiers,
        bool $hasTraderTierRates,
    ): string {
        if ($hasTraderTierRates || abs($effectiveRate - $baseRate) >= 0.0001) {
            return 'individual';
        }

        return $hasGatewayTiers ? 'gateway_tier' : 'gateway_flat';
    }

    private function resolveBaseRate(
        PaymentGateway $gateway,
        CommissionOperationType $operationType,
        int $amount,
        string $currencyCode,
    ): float {
        return $this->commissionRateResolver->resolve(
            paymentGateway: $gateway,
            amount: Money::fromPrecision((string) $amount, $currencyCode),
            operationType: $operationType,
            merchant: null,
            trader: null,
        )->traderCommissionRate;
    }

    private function resolveEffectiveRate(
        User $trader,
        PaymentGateway $gateway,
        CommissionOperationType $operationType,
        int $amount,
        string $currencyCode,
    ): float {
        return $this->commissionRateResolver->resolve(
            paymentGateway: $gateway,
            amount: Money::fromPrecision((string) $amount, $currencyCode),
            operationType: $operationType,
            merchant: null,
            trader: $trader,
        )->traderCommissionRate;
    }

    private function representativeAmount(int $minAmount, int $maxAmount): int
    {
        return (int) floor(($minAmount + $maxAmount) / 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeRow(
        PaymentGateway $gateway,
        CommissionOperationType $operationType,
        ?int $minAmount,
        ?int $maxAmount,
        float $effectiveRate,
        float $baseRate,
        string $source,
    ): array {
        return [
            'id' => sprintf(
                '%d-%s-%s-%s',
                $gateway->id,
                $operationType->value,
                $minAmount ?? 'all',
                $maxAmount ?? 'all',
            ),
            'payment_gateway_id' => $gateway->id,
            'payment_gateway_name' => $gateway->name_with_currency ?? $gateway->name,
            'operation_type' => $operationType->value,
            'min_amount' => $minAmount,
            'max_amount' => $maxAmount,
            'trader_commission_rate' => $effectiveRate,
            'base_trader_commission_rate' => $baseRate,
            'source' => $source,
            'gateway_sort' => $gateway->name,
        ];
    }
}
