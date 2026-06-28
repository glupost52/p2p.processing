<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Rules\TurkishIbanRule;
use PHPUnit\Framework\TestCase;

class TurkishIbanRuleTest extends TestCase
{
    public function test_accepts_valid_turkish_iban(): void
    {
        $rule = new TurkishIbanRule();
        $failed = false;

        $rule->validate('detail', 'TR330006100519786457841326', function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    public function test_accepts_valid_iban_with_spaces(): void
    {
        $rule = new TurkishIbanRule();
        $failed = false;

        $rule->validate('detail', 'TR33 0006 1005 1978 6457 8413 26', function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    public function test_accepts_user_style_turkish_iban(): void
    {
        $rule = new TurkishIbanRule();
        $failed = false;

        $rule->validate('detail', 'TR700001009011105858305001', function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    public function test_rejects_invalid_length(): void
    {
        $rule = new TurkishIbanRule();
        $message = null;

        $rule->validate('detail', 'TR123456789', function (string $msg) use (&$message) {
            $message = $msg;
        });

        $this->assertNotNull($message);
    }

    public function test_rejects_invalid_checksum(): void
    {
        $rule = new TurkishIbanRule();
        $message = null;

        $rule->validate('detail', 'TR000000000000000000000000', function (string $msg) use (&$message) {
            $message = $msg;
        });

        $this->assertNotNull($message);
    }
}
