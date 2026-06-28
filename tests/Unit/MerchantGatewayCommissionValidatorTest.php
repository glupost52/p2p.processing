<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\CommissionOperationType;
use App\Models\PaymentGateway;
use App\Models\PaymentGatewayCommissionTier;
use App\Services\Commission\Exceptions\CommissionTierException;
use App\Services\Commission\MerchantGatewayCommissionValidator;
use PHPUnit\Framework\TestCase;

class MerchantGatewayCommissionValidatorTest extends TestCase
{
    private MerchantGatewayCommissionValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new MerchantGatewayCommissionValidator();
    }

    public function test_rejects_merchant_tier_total_below_gateway_trader_rate(): void
    {
        $gateway = $this->makeGateway([
            $this->makeGatewayTier(1000, 50000, 8, 12),
        ]);

        $this->expectException(CommissionTierException::class);

        $this->validator->validateSingleGatewaySettings($gateway, [
            'commission_mode' => 'tiered',
            'custom_gateway_commission_tiers' => [
                [
                    'min_amount' => 1000,
                    'max_amount' => 10000,
                    'total_service_commission_rate' => 7,
                ],
            ],
        ]);
    }

    public function test_accepts_valid_merchant_tier_total(): void
    {
        $gateway = $this->makeGateway([
            $this->makeGatewayTier(1000, 50000, 8, 12),
        ]);

        $this->validator->validateSingleGatewaySettings($gateway, [
            'commission_mode' => 'tiered',
            'custom_gateway_commission_tiers' => [
                [
                    'min_amount' => 1000,
                    'max_amount' => 10000,
                    'total_service_commission_rate' => 12,
                ],
            ],
        ]);

        $this->assertTrue(true);
    }

    public function test_rejects_flat_merchant_total_below_gateway_trader_rate(): void
    {
        $gateway = $this->makeGateway([]);

        $this->expectException(CommissionTierException::class);

        $this->validator->validateSingleGatewaySettings($gateway, [
            'commission_mode' => 'flat',
            'custom_gateway_commission' => 4,
        ]);
    }

    public function test_rejects_overlapping_merchant_tiers(): void
    {
        $gateway = $this->makeGateway([]);

        $this->expectException(CommissionTierException::class);

        $this->validator->validateSingleGatewaySettings($gateway, [
            'commission_mode' => 'tiered',
            'custom_gateway_commission_tiers' => [
                [
                    'min_amount' => 1000,
                    'max_amount' => 10000,
                    'total_service_commission_rate' => 10,
                ],
                [
                    'min_amount' => 9000,
                    'max_amount' => 20000,
                    'total_service_commission_rate' => 9,
                ],
            ],
        ]);
    }

    public function test_accepts_merchant_tiers_when_ui_order_differs_from_amount_order(): void
    {
        $gateway = $this->makeGateway([]);

        $this->validator->validateSingleGatewaySettings($gateway, [
            'commission_mode' => 'tiered',
            'custom_gateway_commission_tiers' => [
                [
                    'min_amount' => 1000,
                    'max_amount' => 15000,
                    'total_service_commission_rate' => 16,
                ],
                [
                    'min_amount' => 100,
                    'max_amount' => 999,
                    'total_service_commission_rate' => 18,
                ],
            ],
        ]);

        $this->assertTrue(true);
    }

    /**
     * @param array<int, PaymentGatewayCommissionTier> $tiers
     */
    private function makeGateway(array $tiers): PaymentGateway
    {
        $gateway = new PaymentGateway([
            'trader_commission_rate_for_orders' => 6,
            'total_service_commission_rate_for_orders' => 10,
        ]);
        $gateway->id = 1;
        $gateway->setRelation('commissionTiers', collect($tiers));

        return $gateway;
    }

    private function makeGatewayTier(int $min, int $max, float $trader, float $total): PaymentGatewayCommissionTier
    {
        return new PaymentGatewayCommissionTier([
            'payment_gateway_id' => 1,
            'operation_type' => CommissionOperationType::ORDER,
            'min_amount' => $min,
            'max_amount' => $max,
            'trader_commission_rate' => $trader,
            'total_service_commission_rate' => $total,
            'sort_order' => $min,
        ]);
    }
}
