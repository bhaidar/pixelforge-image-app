<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\GenerationSettler;
use App\Services\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerationSettlerTest extends TestCase
{
    use RefreshDatabase;

    private const HOLD = 500_000_000;

    private function makeGeneration(int $balance = 5_000_000_000): Generation
    {
        $user = User::factory()->create();
        app(Wallet::class)->credit($user, 'grant', $balance);

        $generation = Generation::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'pixelforge_id' => 'gen_1',
            'status' => GenerationStatus::Queued,
            'prompt' => 'a cat',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => [],
            'estimated_min_nanos' => 100_000_000,
            'estimated_max_nanos' => self::HOLD,
        ]);
        app(Wallet::class)->hold($generation);

        return $generation->fresh();
    }

    private function snapshot(string $status, array $extra = []): array
    {
        return array_merge([
            'id' => 'gen_1',
            'status' => $status,
            'metadata' => [],
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'usage' => null,
            'image_url' => null,
            'image_url_expires_at' => null,
            'error' => null,
            'completed_at' => '2026-11-04T14:03:29Z',
        ], $extra);
    }

    private function succeeded(): array
    {
        return $this->snapshot('succeeded', [
            'usage' => ['input_text_tokens' => 13, 'input_image_tokens' => 0, 'output_tokens' => 280, 'reasoning_tokens' => 211, 'total_tokens' => 504],
            'image_url' => 'https://img.test/a.png',
            'image_url_expires_at' => '2026-11-05T14:03:29Z',
        ]);
    }

    public function test_success_records_exact_cost_and_charge(): void
    {
        $generation = $this->makeGeneration();

        app(GenerationSettler::class)->apply($this->succeeded());

        $generation->refresh();
        // 13*5000 + 280*40000 + 211*12500 = 65000 + 11200000 + 2637500
        $this->assertSame(13_902_500, $generation->provider_cost_nanos);
        $this->assertSame(17_378_125, $generation->charge_nanos);
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(5_000_000_000 - 17_378_125, $generation->user->fresh()->balance_nanos);
    }

    public function test_duplicate_success_event_charges_once(): void
    {
        $generation = $this->makeGeneration();
        $settler = app(GenerationSettler::class);

        $settler->apply($this->succeeded());
        $settler->apply($this->succeeded());

        $this->assertSame(1, LedgerEntry::where('generation_id', $generation->id)->where('type', 'charge')->count());
        $this->assertSame(5_000_000_000 - 17_378_125, $generation->user->fresh()->balance_nanos);
    }

    public function test_late_processing_event_does_not_move_status_back(): void
    {
        $generation = $this->makeGeneration();
        $settler = app(GenerationSettler::class);

        $settler->apply($this->succeeded());
        $settler->apply($this->snapshot('processing', ['completed_at' => null]));

        $this->assertSame(GenerationStatus::Succeeded, $generation->fresh()->status);
    }

    public function test_failure_is_free_and_releases_hold(): void
    {
        $generation = $this->makeGeneration();

        app(GenerationSettler::class)->apply($this->snapshot('failed', [
            'error' => ['code' => 'generation_failed', 'message' => 'nope'],
        ]));

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame(0, $generation->charge_nanos);
        $this->assertSame(5_000_000_000, $generation->user->fresh()->balance_nanos);
    }

    public function test_failed_never_flips_to_succeeded(): void
    {
        $generation = $this->makeGeneration();
        $settler = app(GenerationSettler::class);

        $settler->apply($this->snapshot('failed', ['error' => ['code' => 'internal_error', 'message' => 'x']]));
        $settler->apply($this->succeeded());

        $this->assertSame(GenerationStatus::Failed, $generation->fresh()->status);
        $this->assertSame(0, LedgerEntry::where('type', 'charge')->count());
    }

    public function test_processing_updates_status_without_settling(): void
    {
        $generation = $this->makeGeneration();

        app(GenerationSettler::class)->apply($this->snapshot('processing', ['completed_at' => null]));

        $this->assertSame(GenerationStatus::Processing, $generation->fresh()->status);
        $this->assertSame(5_000_000_000 - self::HOLD, $generation->user->fresh()->balance_nanos);
    }
}
