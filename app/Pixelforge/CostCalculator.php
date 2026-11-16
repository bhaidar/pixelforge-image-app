<?php

namespace App\Pixelforge;

final class CostCalculator
{
    public const MARKUP_PERCENT = 25;

    /** Provider cost in nanodollars. Exact: every rate is a whole number of nanodollars per token. */
    public static function cost(Usage $usage, TokenRates $rates): int
    {
        return $usage->inputText * $rates->inputText
            + $usage->inputImage * $rates->inputImage
            + $usage->output * $rates->output
            + $usage->reasoning * $rates->reasoning;
    }

    /** What the user pays: provider cost plus markup, rounded half up to a whole nanodollar. */
    public static function charge(int $providerCostNanos): int
    {
        return intdiv($providerCostNanos * (100 + self::MARKUP_PERCENT) + 50, 100);
    }
}
