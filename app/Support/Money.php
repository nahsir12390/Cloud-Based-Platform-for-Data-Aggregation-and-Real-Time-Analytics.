<?php

namespace App\Support;

class Money
{
    /** Convert a validated nonnegative decimal amount to integer kobo. */
    public static function minor(string $amount): int
    {
        [$whole,$fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }
}
