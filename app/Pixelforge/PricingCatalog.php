<?php

namespace App\Pixelforge;

use Illuminate\Support\Facades\Cache;

/** Builds an Estimator from the live public pricing endpoint, cached briefly. */
class PricingCatalog
{
    public function __construct(private readonly PixelforgeClient $client) {}

    public function estimator(): Estimator
    {
        $pricing = Cache::remember('pixelforge.pricing', now()->addMinutes(10), fn () => $this->client->pricing());

        return new Estimator(
            TokenRates::fromApi($pricing['rates']),
            $pricing['tokens']['characters_per_text_token'],
            (float) $pricing['tokens']['text_token_variance'],
            $pricing['tokens']['per_reference_image'],
            $pricing['tokens']['output'],
            $pricing['tokens']['reasoning'],
        );
    }
}
