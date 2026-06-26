<?php

declare(strict_types=1);

/**
 * Security audit: ban seed test users, reset admin passwords.
 * Run: php8.3 deploy/audit-users.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$testEmails = [
    'admin@example.com',
    'trader@example.com',
    'merchant@example.com',
    'teamleader@example.com',
    'support@example.com',
];

$now = now();
$report = [];

foreach ($testEmails as $email) {
    $user = User::query()->where('email', $email)->first();
    if (! $user) {
        continue;
    }

    $user->update([
        'banned_at' => $now,
        'is_online' => false,
    ]);

    $report[] = "BANNED test user: {$email} (id {$user->id})";
}

$primaryAdmin = User::query()->where('email', 'administrator')->first();

if (! $primaryAdmin) {
    $primaryAdmin = User::query()->role('Super Admin')->orderBy('id')->first();
}

if ($primaryAdmin) {
    $adminPassword = Str::password(16, symbols: false);
    $primaryAdmin->update([
        'password' => Hash::make($adminPassword),
        'api_access_token' => strtolower(Str::random(32)),
        'apk_access_token' => strtolower(Str::random(32)),
    ]);

    $report[] = "RESET Super Admin: {$primaryAdmin->email} (id {$primaryAdmin->id})";
    $report[] = "NEW_PASSWORD={$adminPassword}";
    $report[] = 'NEW_API_ACCESS_TOKEN=' . $primaryAdmin->api_access_token;
} else {
    $report[] = 'WARN: Super Admin not found';
}

$merchant = User::query()->where('email', 'pump2p@proton.me')->first();

if ($merchant) {
    $merchant->update([
        'api_access_token' => strtolower(Str::random(32)),
        'apk_access_token' => strtolower(Str::random(32)),
    ]);
    $report[] = "ROTATED API tokens: pump2p@proton.me (password unchanged)";
    $report[] = 'MERCHANT_API_ACCESS_TOKEN=' . $merchant->api_access_token;
}

echo implode(PHP_EOL, $report) . PHP_EOL;
