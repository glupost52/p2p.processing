<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class TurkishIbanRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Поле :attribute должно быть турецким IBAN (TR + 24 цифры).');

            return;
        }

        $iban = strtoupper(preg_replace('/\s+/u', '', $value) ?? '');

        if (! preg_match('/^TR\d{24}$/', $iban)) {
            $fail('Поле :attribute должно быть турецким IBAN: TR и 24 цифры.');

            return;
        }

        if (! $this->isValidIbanChecksum($iban)) {
            $fail('Поле :attribute содержит некорректный контрольный код IBAN.');
        }
    }

    private function isValidIbanChecksum(string $iban): bool
    {
        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numeric = '';

        foreach (str_split($rearranged) as $character) {
            $numeric .= ctype_alpha($character)
                ? (string) (ord($character) - 55)
                : $character;
        }

        $remainder = 0;

        foreach (str_split($numeric) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return $remainder === 1;
    }
}
