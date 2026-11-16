<?php

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\CostCalculator;
use App\Pixelforge\TokenRates;
use App\Pixelforge\Usage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point for turning a Pixelforge generation snapshot (from a webhook
 * or from polling) into local state. Idempotent and order-tolerant: a generation
 * never moves backwards, and it is settled (charged or released) exactly once.
 */
class GenerationSettler
{
    public function __construct(private readonly Wallet $wallet) {}

    public function find(array $snapshot): ?Generation
    {
        return Generation::where('pixelforge_id', $snapshot['id'])->first()
            ?? Generation::where('uuid', $snapshot['metadata']['generation_uuid'] ?? '')->first();
    }

    public function apply(array $snapshot): ?Generation
    {
        $generation = $this->find($snapshot);

        if (! $generation) {
            return null;
        }

        return DB::transaction(function () use ($generation, $snapshot) {
            $generation = Generation::lockForUpdate()->find($generation->id);
            $incoming = GenerationStatus::from($snapshot['status']);

            if ($generation->pixelforge_id === null) {
                $generation->pixelforge_id = $snapshot['id'];
            }

            if ($incoming->rank() > $generation->status->rank()) {
                $generation->status = $incoming;

                match ($incoming) {
                    GenerationStatus::Succeeded => $this->succeed($generation, $snapshot),
                    GenerationStatus::Failed => $this->fail($generation, $snapshot['error']['code'] ?? 'generation_failed', $snapshot['error']['message'] ?? 'Generation failed.', $snapshot['completed_at'] ?? null),
                    default => null,
                };
            }

            $generation->save();

            return $generation;
        });
    }

    /** Fail a generation that Pixelforge never accepted (e.g. content policy, retries exhausted). */
    public function failLocally(Generation $generation, string $code, string $message): void
    {
        DB::transaction(function () use ($generation, $code, $message) {
            $generation = Generation::lockForUpdate()->find($generation->id);

            if ($generation->status->isFinal()) {
                return;
            }

            $generation->status = GenerationStatus::Failed;
            $this->fail($generation, $code, $message, null);
            $generation->save();
        });
    }

    private function succeed(Generation $generation, array $snapshot): void
    {
        $cost = CostCalculator::cost(Usage::fromApi($snapshot['usage']), TokenRates::fromApi($snapshot['rates']));
        $charge = CostCalculator::charge($cost);

        $generation->fill([
            'rates' => $snapshot['rates'],
            'usage' => $snapshot['usage'],
            'provider_cost_nanos' => $cost,
            'charge_nanos' => $charge,
            'image_url' => $snapshot['image_url'],
            'image_url_expires_at' => $snapshot['image_url_expires_at'],
            'completed_at' => Carbon::parse($snapshot['completed_at']),
            'settled_at' => now(),
        ]);

        $this->wallet->settle($generation, $charge);
    }

    private function fail(Generation $generation, string $code, string $message, ?string $completedAt): void
    {
        $generation->fill([
            'error_code' => $code,
            'error_message' => $message,
            'provider_cost_nanos' => 0,
            'charge_nanos' => 0,
            'completed_at' => $completedAt ? Carbon::parse($completedAt) : now(),
            'settled_at' => now(),
        ]);

        $this->wallet->settle($generation, 0);
    }
}
