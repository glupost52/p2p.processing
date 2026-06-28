<?php

declare(strict_types=1);

namespace App\Services\Commission\Values;

final readonly class ResolvedCommissionRates
{
    public function __construct(
        public float $totalServiceCommissionRate,
        public float $traderCommissionRate,
        public float $primeTimeBonusRate = 0.0,
    ) {}

    public function traderCommissionRateWithPrimeTime(): float
    {
        return round($this->traderCommissionRate + $this->primeTimeBonusRate, 2);
    }
}
