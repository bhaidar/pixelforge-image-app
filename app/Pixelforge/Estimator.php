<?php

namespace App\Pixelforge;

/** Pre-submission price range. Output and image tokens are exact; text and reasoning tokens are bounded. */
final class Estimator
{
    /**
     * @param  array<string, array<string, int>>  $output  quality => size => tokens
     * @param  array<string, array{min: int, max: int}>  $reasoning  quality => bounds
     */
    public function __construct(
        private readonly TokenRates $rates,
        private readonly int $charactersPerTextToken,
        private readonly float $textTokenVariance,
        private readonly int $perReferenceImage,
        private readonly array $output,
        private readonly array $reasoning,
    ) {}

    public function estimate(string $prompt, string $size, string $quality, int $referenceImages): CostEstimate
    {
        $baseText = (int) ceil(mb_strlen($prompt) / $this->charactersPerTextToken);
        $minText = (int) floor($baseText * (1 - $this->textTokenVariance));
        $maxText = (int) ceil($baseText * (1 + $this->textTokenVariance));

        $min = $this->cost($minText, $size, $quality, $referenceImages, $this->reasoning[$quality]['min']);
        $max = $this->cost($maxText, $size, $quality, $referenceImages, $this->reasoning[$quality]['max']);

        return new CostEstimate($min, $max, CostCalculator::charge($min), CostCalculator::charge($max));
    }

    private function cost(int $text, string $size, string $quality, int $images, int $reasoning): int
    {
        return CostCalculator::cost(
            new Usage($text, $images * $this->perReferenceImage, $this->output[$quality][$size], $reasoning),
            $this->rates,
        );
    }
}
