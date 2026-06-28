<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\CommissionOperationType;
use App\Models\Merchant;
use App\Models\PaymentGateway;
use App\Models\PaymentGatewayCommissionTier;
use App\Models\TraderCommissionRate;
use App\Models\User;
use App\Models\ValueObjects\Settings\PrimeTimeSettings;
use App\Services\Commission\CommissionRateResolver;
use App\Services\Money\Money;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class CommissionRateResolverTest extends TestCase
{
    private CommissionRateResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new CommissionRateResolver();
        Carbon::setTestNow(Carbon::parse('2026-06-28 14:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_uses_gateway_defaults_when_no_tiers_configured(): void
    {
        $gateway = $this->makeGateway([
            'trader_commission_rate_for_orders' => 6,
            'total_service_commission_rate_for_orders' => 10,
        ]);

        $resolved = $this->resolver->resolve(
            paymentGateway: $gateway,
            amount: Money::fromPrecision('15000', 'RUB'),
            operationType: CommissionOperationType::ORDER,
        );

        $this->assertSame(10.0, $resolved->totalServiceCommissionRate);
        $this->assertSame(6.0, $resolved->traderCommissionRate);
    }

    public function test_uses_gateway_tier_when_amount_matches(): void
    {
        $gateway = $this->makeGateway([
            'trader_commission_rate_for_orders' => 6,
            'total_service_commission_rate_for_orders' => 10,
        ], [
            $this->makeGatewayTier(1000, 5000, 8, 12),
            $this->makeGatewayTier(5001, 50000, 6, 10),
        ]);

        $resolved = $this->resolver->resolve(
            paymentGateway: $gateway,
            amount: Money::fromPrecision('15000', 'RUB'),
            operationType: CommissionOperationType::ORDER,
        );

        $this->assertSame(10.0, $resolved->totalServiceCommissionRate);
        $this->assertSame(6.0, $resolved->traderCommissionRate);
    }

    public function test_merchant_flat_override_matches_current_gateway_factory_behavior(): void
    {
        $gateway = $this->makeGateway([
            'trader_commission_rate_for_orders' => 6,
            'total_service_commission_rate_for_orders' => 10,
        ]);
        $merchant = $this->makeMerchant([
            $gateway->id => ['custom_gateway_commission' => 9],
        ]);

        $resolved = $this->resolver->resolve(
            paymentGateway: $gateway,
            amount: Money::fromPrecision('15000', 'RUB'),
            operationType: CommissionOperationType::ORDER,
            merchant: $merchant,
        );

        $this->assertSame(9.0, $resolved->totalServiceCommissionRate);
        $this->assertSame(6.0, $resolved->traderCommissionRate);
    }

    public function test_merchant_flat_zero_override_matches_current_gateway_factory_behavior(): void
    {
        $gateway = $this->makeGateway([
            'trader_commission_rate_for_orders' => 6,
            'total_service_commission_rate_for_orders' => 10,
        ]);
        $merchant = $this->makeMerchant([
            $gateway->id => ['custom_gateway_commission' => 0],
        ]);

        $resolved = $this->resolver->resolve(
            paymentGateway: $gateway,
            amount: Money::fromPrecision('15000', 'RUB'),
            operationType: CommissionOperationType::ORDER,
            merchant: $merchant,
        );

        $this->assertSame(0.0, $resolved->totalServiceCommissionRate);
        $this->assertSame(6.0, $resolved->traderCommissionRate);
    }

    public function test_merchant_tiered_override_overrides_total_rate_only(): void
    {
        $gateway = $this->makeGateway([
            'trader_commission_rate_for_orders' => 6,
            'total_service_commission_rate_for_orders' => 10,
        ], [
            $this->makeGatewayTier(1000, 50000, 6, 10),
        ]);
        $merchant = $this->makeMerchant([
            $gateway->id => [
                'commission_mode' => 'tiered',
                'custom_gateway_commission_tiers' => [
                    [
                        'min_amount' => 1000,
                        'max_amount' => 20000,
                        'total_service_commission_rate' => 11,
                    ],
                    [
                        'min_amount' => 20001,
                        'max_amount' => 50000,
                        'total_service_commission_rate' => 9,
                    ],
                ],
            ],
        ]);

        $resolved = $this->resolver->resolve(
            paymentGateway: $gateway,
            amount: Money::fromPrecision('15000', 'RUB'),
            operationType: CommissionOperationType::ORDER,
            merchant: $merchant,
        );

        $this->assertSame(11.0, $resolved->totalServiceCommissionRate);
        $this->assertSame(6.0, $resolved->traderCommissionRate);
    }

    public function test_trader_flat_override_overrides_trader_rate_only(): void
    {
        $gateway = $this->makeGateway([
            'trader_commission_rate_for_orders' => 6,
            'total_service_commission_rate_for_orders' => 10,
        ]);
        $trader = $this->makeTrader([
            $this->makeTraderRate($gateway->id, null, null, 7.5),
        ]);

        $resolved = $this->resolver->resolve(
            paymentGateway: $gateway,
            amount: Money::fromPrecision('15000', 'RUB'),
            operationType: CommissionOperationType::ORDER,
            trader: $trader,
        );

        $this->assertSame(10.0, $resolved->totalServiceCommissionRate);
        $this->assertSame(7.5, $resolved->traderCommissionRate);
    }

    public function test_trader_tier_override_takes_priority_over_gateway_tier(): void
    {
        $gateway = $this->makeGateway([
            'trader_commission_rate_for_orders' => 6,
            'total_service_commission_rate_for_orders' => 10,
        ], [
            $this->makeGatewayTier(1000, 50000, 6, 10),
        ]);
        $trader = $this->makeTrader([
            $this->makeTraderRate($gateway->id, 10000, 20000, 7.5),
        ]);

        $resolved = $this->resolver->resolve(
            paymentGateway: $gateway,
            amount: Money::fromPrecision('15000', 'RUB'),
            operationType: CommissionOperationType::ORDER,
            trader: $trader,
        );

        $this->assertSame(10.0, $resolved->totalServiceCommissionRate);
        $this->assertSame(7.5, $resolved->traderCommissionRate);
    }

    public function test_applies_prime_time_bonus_to_trader_rate(): void
    {
        $gateway = $this->makeGateway([
            'trader_commission_rate_for_orders' => 6,
            'total_service_commission_rate_for_orders' => 10,
        ]);
        $primeTime = new PrimeTimeSettings(
            starts: '13:00:00',
            ends: '15:00:00',
            rate: 0.5,
        );

        $resolved = $this->resolver->resolve(
            paymentGateway: $gateway,
            amount: Money::fromPrecision('15000', 'RUB'),
            operationType: CommissionOperationType::ORDER,
            primeTime: $primeTime,
        );

        $this->assertSame(0.5, $resolved->primeTimeBonusRate);
        $this->assertSame(6.5, $resolved->traderCommissionRate);
        $this->assertSame(6.5, $resolved->traderCommissionRateWithPrimeTime());
    }

    public function test_payout_operation_uses_payout_default_rates(): void
    {
        $gateway = $this->makeGateway([
            'trader_commission_rate_for_orders' => 6,
            'total_service_commission_rate_for_orders' => 10,
            'trader_commission_rate_for_payouts' => 2,
            'total_service_commission_rate_for_payouts' => 3,
        ]);

        $resolved = $this->resolver->resolve(
            paymentGateway: $gateway,
            amount: Money::fromPrecision('15000', 'RUB'),
            operationType: CommissionOperationType::PAYOUT,
        );

        $this->assertSame(3.0, $resolved->totalServiceCommissionRate);
        $this->assertSame(2.0, $resolved->traderCommissionRate);
    }

    public function test_throws_when_trader_rate_exceeds_total_rate(): void
    {
        $gateway = $this->makeGateway([
            'trader_commission_rate_for_orders' => 6,
            'total_service_commission_rate_for_orders' => 10,
        ]);
        $trader = $this->makeTrader([
            $this->makeTraderRate($gateway->id, null, null, 11),
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $this->resolver->resolve(
            paymentGateway: $gateway,
            amount: Money::fromPrecision('15000', 'RUB'),
            operationType: CommissionOperationType::ORDER,
            trader: $trader,
        );
    }

    /**
     * @param array<string, float|int> $attributes
     * @param array<int, PaymentGatewayCommissionTier> $tiers
     */
    private function makeGateway(array $attributes, array $tiers = []): PaymentGateway
    {
        $gateway = new PaymentGateway(array_merge([
            'trader_commission_rate_for_orders' => 0,
            'total_service_commission_rate_for_orders' => 0,
            'trader_commission_rate_for_payouts' => 0,
            'total_service_commission_rate_for_payouts' => 0,
        ], $attributes));
        $gateway->id = 1;
        $gateway->setRelation('commissionTiers', collect($tiers));

        return $gateway;
    }

    private function makeGatewayTier(
        int $minAmount,
        int $maxAmount,
        float $traderRate,
        float $totalRate,
    ): PaymentGatewayCommissionTier {
        $tier = new PaymentGatewayCommissionTier([
            'payment_gateway_id' => 1,
            'operation_type' => CommissionOperationType::ORDER,
            'min_amount' => $minAmount,
            'max_amount' => $maxAmount,
            'trader_commission_rate' => $traderRate,
            'total_service_commission_rate' => $totalRate,
            'sort_order' => $minAmount,
        ]);

        return $tier;
    }

    /**
     * @param array<int|string, array<string, mixed>> $gatewaySettings
     */
    private function makeMerchant(array $gatewaySettings): Merchant
    {
        $merchant = new Merchant([
            'gateway_settings' => $gatewaySettings,
        ]);
        $merchant->id = 1;

        return $merchant;
    }

    /**
     * @param array<int, TraderCommissionRate> $rates
     */
    private function makeTrader(array $rates): User
    {
        $trader = new User();
        $trader->id = 42;
        $trader->setRelation('traderCommissionRates', collect($rates));

        return $trader;
    }

    private function makeTraderRate(
        int $gatewayId,
        ?int $minAmount,
        ?int $maxAmount,
        float $traderRate,
    ): TraderCommissionRate {
        return new TraderCommissionRate([
            'user_id' => 42,
            'payment_gateway_id' => $gatewayId,
            'operation_type' => CommissionOperationType::ORDER,
            'min_amount' => $minAmount,
            'max_amount' => $maxAmount,
            'trader_commission_rate' => $traderRate,
            'is_active' => true,
        ]);
    }
}
