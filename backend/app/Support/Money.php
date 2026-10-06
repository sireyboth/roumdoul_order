<?php

namespace App\Support;

/**
 * Prices are stored as whole minor units so totals never drift from rounding:
 * USD in cents (1.50 => 150), KHR in whole riel (6000 => 6000).
 */
final class Money
{
    public static function factor(string $currency): int
    {
        return strtoupper($currency) === 'KHR' ? 1 : 100;
    }

    /** "1.50" typed in a form => 150 */
    public static function toMinor(float|int|string|null $amount, string $currency): ?int
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return (int) round(((float) $amount) * self::factor($currency));
    }

    /** 150 => 1.5 (for filling a form field) */
    public static function fromMinor(?int $minor, string $currency): float|int|null
    {
        if ($minor === null) {
            return null;
        }

        $factor = self::factor($currency);

        return $factor === 1 ? $minor : round($minor / $factor, 2);
    }

    /** 150 => "$1.50", 6000 => "6,000៛" */
    public static function format(?int $minor, string $currency): string
    {
        if ($minor === null) {
            return '—';
        }

        return strtoupper($currency) === 'KHR'
            ? number_format($minor).'៛'
            : '$'.number_format($minor / 100, 2);
    }
}
