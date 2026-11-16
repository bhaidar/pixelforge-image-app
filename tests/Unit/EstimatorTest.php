<?php

namespace Tests\Unit;

use App\Pixelforge\Estimator;
use App\Pixelforge\TokenRates;
use PHPUnit\Framework\TestCase;

class EstimatorTest extends TestCase
{
    private function estimator(): Estimator
    {
        return new Estimator(
            TokenRates::fromApi([
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ]),
            charactersPerTextToken: 4,
            textTokenVariance: 0.15,
            perReferenceImage: 765,
            output: ['high' => ['1536x1024' => 6450], 'low' => ['1024x1024' => 280]],
            reasoning: ['high' => ['min' => 1000, 'max' => 8000], 'low' => ['min' => 0, 'max' => 500]],
        );
    }

    public function test_max_matches_docs_worked_example(): void
    {
        $e = $this->estimator()->estimate(str_repeat('a', 400), '1536x1024', 'high', 2);

        $this->assertSame(373_875_000, $e->maxProviderNanos);
    }

    public function test_min_uses_lowest_text_and_reasoning(): void
    {
        $e = $this->estimator()->estimate(str_repeat('a', 400), '1536x1024', 'high', 2);

        // text floor(100*0.85)=85, reasoning 1000
        $this->assertSame(85 * 5000 + 1530 * 10_000 + 6450 * 40_000 + 1000 * 12_500, $e->minProviderNanos);
    }

    public function test_counts_unicode_characters_not_bytes(): void
    {
        $e = $this->estimator()->estimate(str_repeat('é', 8), '1024x1024', 'low', 0);

        // 8 chars -> 2 tokens, max ceil(2*1.15)=3
        $this->assertSame(3 * 5000 + 280 * 40_000 + 500 * 12_500, $e->maxProviderNanos);
    }

    public function test_charge_range_includes_markup(): void
    {
        $e = $this->estimator()->estimate('hi', '1024x1024', 'low', 0);

        $this->assertSame((int) round($e->maxProviderNanos * 1.25), $e->maxChargeNanos);
    }
}
