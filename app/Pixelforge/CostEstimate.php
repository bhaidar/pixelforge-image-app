<?php

namespace App\Pixelforge;

final readonly class CostEstimate
{
    public function __construct(
        public int $minProviderNanos,
        public int $maxProviderNanos,
        public int $minChargeNanos,
        public int $maxChargeNanos,
    ) {}
}
