<?php

namespace App\Pixelforge;

use InvalidArgumentException;

/** Rates in nanodollars per single token (API rate is dollars per 1M tokens, so x1000). */
final readonly class TokenRates
{
    public function __construct(
        public int $inputText,
        public int $inputImage,
        public int $output,
        public int $reasoning,
    ) {}

    /** @param array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string} $rates */
    public static function fromApi(array $rates): self
    {
        return new self(
            self::parse($rates['input_text_tokens']),
            self::parse($rates['input_image_tokens']),
            self::parse($rates['output_tokens']),
            self::parse($rates['reasoning_tokens']),
        );
    }

    private static function parse(string $dollarsPerMillion): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,3}))?$/', $dollarsPerMillion, $m)) {
            throw new InvalidArgumentException("Unsupported rate precision: {$dollarsPerMillion}");
        }

        return (int) $m[1] * 1000 + (int) str_pad($m[2] ?? '', 3, '0');
    }
}
