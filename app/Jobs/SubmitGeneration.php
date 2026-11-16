<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use App\Services\GenerationSettler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    public const MAX_ATTEMPTS = 6;

    public int $tries = 10;

    public function __construct(public int $generationId) {}

    /** Unexpected crash: if Pixelforge never accepted it, fail and free the hold. Otherwise polling settles it. */
    public function failed(\Throwable $e): void
    {
        $generation = Generation::find($this->generationId);

        if ($generation && ! $generation->pixelforge_id) {
            app(GenerationSettler::class)->failLocally($generation, 'internal_error', 'Something went wrong submitting your generation. You were not charged.');
        }
    }

    public function handle(PixelforgeClient $client, GenerationSettler $settler): void
    {
        $generation = Generation::find($this->generationId);

        if (! $generation || $generation->pixelforge_id || $generation->status->isFinal()) {
            return;
        }

        try {
            $snapshot = $client->createGeneration([
                'prompt' => $generation->prompt,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'reference_images' => $generation->reference_images,
                'metadata' => ['generation_uuid' => $generation->uuid],
            ], $generation->uuid);
        } catch (PixelforgeException $e) {
            $this->handleFailure($generation, $settler, $e);

            return;
        }

        $settler->apply($snapshot);
    }

    private function handleFailure(Generation $generation, GenerationSettler $settler, PixelforgeException $e): void
    {
        if ($e->isRetryable() && $this->attempts() < self::MAX_ATTEMPTS) {
            // Honour Retry-After, backing off further on every attempt.
            $this->release(max($e->retryAfter ?? 0, 2 ** $this->attempts()));

            return;
        }

        $settler->failLocally($generation, $e->errorCode, $this->userMessage($e));
    }

    private function userMessage(PixelforgeException $e): string
    {
        return match ($e->errorCode) {
            'content_policy_violation' => 'Your prompt was rejected by the content policy. You were not charged.',
            'daily_limit_exceeded' => 'The daily generation quota has been reached. Try again tomorrow. You were not charged.',
            'rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded', 'connection_error' => 'Pixelforge is busy right now. Please try again in a moment. You were not charged.',
            default => $e->getMessage().' You were not charged.',
        };
    }
}
