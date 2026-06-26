<?php

declare(strict_types=1);

/**
 * Generate 128x128 PNG placeholders for payment gateway logos.
 * Run on server: php deploy/generate-payment-logos.php
 */

$brandColors = [
    'sberbank' => ['bg' => [33, 160, 56], 'fg' => [255, 255, 255], 'text' => 'СБ'],
    'tinkoff' => ['bg' => [255, 221, 45], 'fg' => [0, 0, 0], 'text' => 'T'],
    'alfabank' => ['bg' => [239, 49, 36], 'fg' => [255, 255, 255], 'text' => 'А'],
    'vtb' => ['bg' => [10, 40, 150], 'fg' => [255, 255, 255], 'text' => 'ВТБ'],
    'gazprombank' => ['bg' => [0, 70, 130], 'fg' => [255, 255, 255], 'text' => 'ГП'],
    'raiffeisenbank' => ['bg' => [255, 204, 0], 'fg' => [0, 0, 0], 'text' => 'RF'],
    'yoomoney' => ['bg' => [139, 61, 255], 'fg' => [255, 255, 255], 'text' => 'Ю'],
    'ozon' => ['bg' => [0, 91, 255], 'fg' => [255, 255, 255], 'text' => 'OZ'],
    'wb_rub' => ['bg' => [203, 17, 171], 'fg' => [255, 255, 255], 'text' => 'WB'],
    'mts' => ['bg' => [227, 6, 19], 'fg' => [255, 255, 255], 'text' => 'МТС'],
    'pochta' => ['bg' => [0, 57, 166], 'fg' => [255, 255, 255], 'text' => 'ПБ'],
    'jandeks-bank' => ['bg' => [255, 204, 0], 'fg' => [0, 0, 0], 'text' => 'Я'],
    'sovkom' => ['bg' => [0, 166, 81], 'fg' => [255, 255, 255], 'text' => 'СК'],
    'ros_bank' => ['bg' => [227, 6, 19], 'fg' => [255, 255, 255], 'text' => 'РБ'],
    'domrfbank' => ['bg' => [0, 166, 81], 'fg' => [255, 255, 255], 'text' => 'ДР'],
    'otp_rub' => ['bg' => [0, 166, 81], 'fg' => [255, 255, 255], 'text' => 'ОТП'],
    'kaspi_kzt' => ['bg' => [242, 48, 48], 'fg' => [255, 255, 255], 'text' => 'K'],
    'halyk_kzt' => ['bg' => [0, 166, 81], 'fg' => [255, 255, 255], 'text' => 'H'],
    'avito' => ['bg' => [0, 175, 90], 'fg' => [255, 255, 255], 'text' => 'AV'],
    'cupis_rub' => ['bg' => [0, 174, 239], 'fg' => [255, 255, 255], 'text' => 'ЦУ'],
];

function initialsFromName(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '?';
    }

    if (preg_match('/^[A-Za-z]/', $name)) {
        $parts = preg_split('/\s+/', $name) ?: [];

        return strtoupper(substr($parts[0], 0, 2));
    }

    $chars = preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

    return mb_strtoupper(implode('', array_slice($chars, 0, 2)));
}

function colorFromCode(string $code): array
{
    $hash = crc32($code);

    return [
        ($hash & 0xFF) % 156 + 50,
        (($hash >> 8) & 0xFF) % 156 + 50,
        (($hash >> 16) & 0xFF) % 156 + 50,
    ];
}

$appRoot = dirname(__DIR__);

if (is_file($appRoot . '/vendor/autoload.php')) {
    require $appRoot . '/vendor/autoload.php';
    $app = require $appRoot . '/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $gateways = App\Models\PaymentGateway::query()
        ->select(['code', 'name', 'logo'])
        ->whereNotNull('logo')
        ->where('logo', '!=', '')
        ->get();
} else {
    fwrite(STDERR, "Laravel bootstrap not found.\n");
    exit(1);
}

$dir = $appRoot . '/storage/app/public/logos';
if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
    fwrite(STDERR, "Cannot create logos directory.\n");
    exit(1);
}

$font = 5;
$created = 0;

foreach ($gateways as $gateway) {
    $filename = (string) $gateway->logo;
    if ($filename === '' || ! str_ends_with(strtolower($filename), '.png')) {
        continue;
    }

    $path = $dir . '/' . $filename;
    if (is_file($path)) {
        continue;
    }

    $code = (string) $gateway->code;
    $preset = $brandColors[$code] ?? null;

    if ($preset) {
        [$r, $g, $b] = $preset['bg'];
        $text = $preset['text'];
        [$fr, $fg, $fb] = $preset['fg'];
    } else {
        [$r, $g, $b] = colorFromCode($code);
        $text = initialsFromName((string) $gateway->name);
        $fr = $fg = $fb = 255;
        if (($r + $g + $b) / 3 > 180) {
            $fr = $fg = $fb = 20;
        }
    }

    $image = imagecreatetruecolor(128, 128);
    $bg = imagecolorallocate($image, $r, $g, $b);
    $fgColor = imagecolorallocate($image, $fr, $fg, $fb);
    imagefilledrectangle($image, 0, 0, 128, 128, $bg);

    $text = mb_substr($text, 0, 4);
    $textWidth = imagefontwidth($font) * strlen($text);
    $textHeight = imagefontheight($font);
    $x = (int) ((128 - $textWidth) / 2);
    $y = (int) ((128 - $textHeight) / 2);
    imagestring($image, $font, max(4, $x), max(4, $y), $text, $fgColor);

    imagepng($image, $path);
    imagedestroy($image);
    $created++;
}

echo "Created {$created} logos in {$dir}\n";
