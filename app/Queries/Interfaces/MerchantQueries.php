<?php

namespace App\Queries\Interfaces;

use App\Models\Merchant;

interface MerchantQueries
{
    public function findByUUID(string $uuid): ?Merchant;

    public function findByID(string $id): ?Merchant;

    public function forget(Merchant $merchant): void;
}
