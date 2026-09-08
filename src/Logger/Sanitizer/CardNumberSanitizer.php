<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Logger\Sanitizer;

use Override;
use Techork\PaymentService\Laravel\Logger\SanitizerInterface;

/**
 * Detects PANs by strict shape: a 13–19 character run of pure digits that
 * passes the Luhn check. Independent of the context key name, so card
 * numbers carried under arbitrary fields (`cardNumber`, `pan`, `number`, …)
 * are still caught — but only when the value itself is already digit-only,
 * which is how every gateway in this app ships them on the wire. Skipping
 * any character substitution before the Luhn step is deliberate: a permissive
 * strip silently folds non-PAN strings (UUIDs, mixed text) into something
 * Luhn-validates and gets them masked as cards.
 */
final readonly class CardNumberSanitizer implements SanitizerInterface
{
    #[Override]
    public function match(string $name, mixed $value): bool
    {
        if (! is_string($value) && ! is_int($value)) {
            return false;
        }

        $value = (string) $value;
        $length = strlen($value);

        return $length >= 13
            && $length <= 19
            && preg_match('/^\d+$/', $value) === 1
            && self::passesLuhn($value);
    }

    /**
     * Doubling every second digit from the right and summing the digits of the products, which is
     * what `Omnipay\Common\Helper::validateLuhn()` did before it went with the rest of Omnipay.
     * Kept digit-for-digit: the sanitiser's whole contract is which strings it decides are cards,
     * and a subtler check would either start masking references or stop masking PANs.
     */
    private static function passesLuhn(string $number): bool
    {
        $digits = '';

        foreach (array_reverse(str_split($number)) as $position => $digit) {
            $digits .= $position % 2 === 1 ? (string) ((int) $digit * 2) : $digit;
        }

        return (int) array_sum(array_map(intval(...), str_split($digits))) % 10 === 0;
    }

    #[Override]
    public function mask(string $name, mixed $value): string
    {
        $number = (string) $value;

        return str_repeat('*', max(strlen($number) - 4, 0)) . substr($number, -4);
    }
}
