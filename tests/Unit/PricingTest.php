<?php

namespace Tests\Unit;

use App\Pixelforge\CostCalculator;
use App\Pixelforge\TokenRates;
use App\Pixelforge\Usage;
use PHPUnit\Framework\TestCase;

class PricingTest extends TestCase
{
    private function rates(): TokenRates
    {
        return TokenRates::fromApi([
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ]);
    }

    public function test_cost_matches_docs_worked_example(): void
    {
        $usage = new Usage(inputText: 115, inputImage: 1530, output: 6450, reasoning: 8000);

        $this->assertSame(373_875_000, CostCalculator::cost($usage, $this->rates()));
    }

    public function test_cost_is_exact_for_odd_token_counts(): void
    {
        $usage = new Usage(inputText: 13, inputImage: 765, output: 1100, reasoning: 211);

        // 13*5000 + 765*10000 + 1100*40000 + 211*12500
        $this->assertSame(65_000 + 7_650_000 + 44_000_000 + 2_637_500, CostCalculator::cost($usage, $this->rates()));
    }

    public function test_charge_adds_25_percent_and_rounds_half_up(): void
    {
        $this->assertSame(467_343_750, CostCalculator::charge(373_875_000));
        $this->assertSame(0, CostCalculator::charge(0));
        $this->assertSame(1, CostCalculator::charge(1)); // 1.25 rounds to 1
        $this->assertSame(3, CostCalculator::charge(2)); // 2.5 rounds half up to 3
    }

    public function test_rates_with_more_than_three_decimals_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TokenRates::fromApi([
            'input_text_tokens' => '5.0001',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ]);
    }
}
