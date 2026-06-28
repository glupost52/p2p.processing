<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;

interface TraderEffectiveCommissionServiceContract
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildForUser(User $trader): array;
}
