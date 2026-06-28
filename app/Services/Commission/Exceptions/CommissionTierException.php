<?php

declare(strict_types=1);

namespace App\Services\Commission\Exceptions;

use Exception;

class CommissionTierException extends Exception
{
    public static function overlappingRanges(int $firstMin, int $firstMax, int $secondMin, int $secondMax): self
    {
        return new self("Commission tiers overlap: [{$firstMin}-{$firstMax}] and [{$secondMin}-{$secondMax}].");
    }

    public static function invalidRateRelation(float $traderRate, float $totalRate): self
    {
        return new self("Trader commission rate ({$traderRate}%) cannot exceed total service rate ({$totalRate}%).");
    }

    public static function invalidRange(int $minAmount, int $maxAmount): self
    {
        return new self("Commission tier min amount ({$minAmount}) cannot exceed max amount ({$maxAmount}).");
    }
}
