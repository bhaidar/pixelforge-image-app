<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubmitGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function pending(): Generation
    {
        $user = User::factory()->create();
        app(Wallet::class)->credit($user, 'grant', 5_000_000_000);

        $generation = Generation::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'status' => GenerationStatus::Pending,
            'prompt' => 'a cat',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => [],
            'estimated_min_nanos' => 1,
            'estimated_max_nanos' => 500_000_000,
        ]);
        app(Wallet::class)->hold($generation);

        return $generation;
    }

    private function submit(Generation $generation): SubmitGeneration
    {
        $job = new SubmitGeneration($generation->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(\App\Pixelforge\PixelforgeClient::class), app(\App\Services\GenerationSettler::class));

        return $job;
    }

    private function accepted(Generation $generation): array
    {
        return [
            'id' => 'gen_abc', 'status' => 'queued', 'metadata' => ['generation_uuid' => $generation->uuid],
            'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        ];
    }

    public function test_accepted_generation_is_marked_queued_and_linked(): void
    {
        $generation = $this->pending();
        Http::fake(['*/generations' => Http::response($this->accepted($generation), 202)]);

        $this->submit($generation);

        $generation->refresh();
        $this->assertSame('gen_abc', $generation->pixelforge_id);
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        Http::assertSent(fn ($r) => $r->header('Idempotency-Key')[0] === $generation->uuid);
    }

    public function test_overload_is_released_back_to_the_queue_honouring_retry_after(): void
    {
        $generation = $this->pending();
        Http::fake(['*/generations' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'busy']], 503, ['Retry-After' => '7'])]);

        $job = $this->submit($generation);

        $job->assertReleased(delay: 7);
        $this->assertSame(GenerationStatus::Pending, $generation->fresh()->status);
    }

    public function test_content_policy_violation_fails_and_frees_the_hold(): void
    {
        $generation = $this->pending();
        Http::fake(['*/generations' => Http::response(['error' => ['code' => 'content_policy_violation', 'message' => 'no']], 400)]);

        $this->submit($generation);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('content_policy_violation', $generation->error_code);
        $this->assertSame(5_000_000_000, $generation->user->fresh()->balance_nanos);
    }

    public function test_daily_limit_is_not_retried(): void
    {
        $generation = $this->pending();
        Http::fake(['*/generations' => Http::response(['error' => ['code' => 'daily_limit_exceeded', 'message' => 'x']], 429)]);

        $job = $this->submit($generation);

        $job->assertNotReleased();
        $this->assertSame(GenerationStatus::Failed, $generation->fresh()->status);
    }
}
