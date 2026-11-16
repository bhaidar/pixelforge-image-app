<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PixelforgeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['pixelforge.webhook_secret' => self::SECRET]);
    }

    private function generation(): Generation
    {
        $user = User::factory()->create();
        app(Wallet::class)->credit($user, 'grant', 5_000_000_000);

        $generation = Generation::create([
            'uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'status' => GenerationStatus::Pending,
            'prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => [],
            'estimated_min_nanos' => 1, 'estimated_max_nanos' => 500_000_000,
        ]);
        app(Wallet::class)->hold($generation);

        return $generation;
    }

    private function event(Generation $generation, string $id, string $type, array $object = []): array
    {
        $status = str_replace('generation.', '', $type);

        return [
            'id' => $id, 'object' => 'event', 'type' => $type, 'created_at' => '2026-11-04T14:03:29Z',
            'data' => ['object' => array_merge([
                'id' => 'gen_abc', 'object' => 'generation', 'status' => $status,
                'metadata' => ['generation_uuid' => $generation->uuid],
                'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
                'usage' => $status === 'succeeded' ? ['input_text_tokens' => 13, 'input_image_tokens' => 0, 'output_tokens' => 280, 'reasoning_tokens' => 211, 'total_tokens' => 504] : null,
                'image_url' => $status === 'succeeded' ? 'https://img.test/a.png' : null,
                'image_url_expires_at' => '2026-11-05T14:03:29Z',
                'error' => null, 'completed_at' => '2026-11-04T14:03:29Z',
            ], $object)],
        ];
    }

    private function deliver(array $event, ?int $timestamp = null, ?string $secret = null)
    {
        $body = json_encode($event);
        $t = $timestamp ?? time();
        $sig = hash_hmac('sha256', "{$t}.{$body}", $secret ?? self::SECRET);

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$t},v1={$sig}",
        ], $body);
    }

    public function test_rejects_bad_signature(): void
    {
        $generation = $this->generation();

        $this->deliver($this->event($generation, 'evt_1', 'generation.succeeded'), secret: 'wrong')->assertStatus(400);

        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_rejects_stale_timestamp(): void
    {
        $generation = $this->generation();

        $this->deliver($this->event($generation, 'evt_1', 'generation.succeeded'), timestamp: time() - 3600)->assertStatus(400);
    }

    public function test_succeeded_event_charges_once_even_when_delivered_twice(): void
    {
        $generation = $this->generation();
        $event = $this->event($generation, 'evt_1', 'generation.succeeded');

        $this->deliver($event)->assertNoContent();
        $this->deliver($event)->assertNoContent();

        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame(1, LedgerEntry::where('type', 'charge')->count());
        $this->assertSame(17_378_125, $generation->fresh()->charge_nanos);
    }

    public function test_same_result_via_a_different_event_id_is_still_charged_once(): void
    {
        $generation = $this->generation();

        $this->deliver($this->event($generation, 'evt_1', 'generation.succeeded'))->assertNoContent();
        $this->deliver($this->event($generation, 'evt_2', 'generation.succeeded'))->assertNoContent();

        $this->assertSame(1, LedgerEntry::where('type', 'charge')->count());
    }

    public function test_out_of_order_processing_after_succeeded_is_ignored(): void
    {
        $generation = $this->generation();

        $this->deliver($this->event($generation, 'evt_2', 'generation.succeeded'))->assertNoContent();
        $this->deliver($this->event($generation, 'evt_1', 'generation.processing'))->assertNoContent();

        $this->assertSame(GenerationStatus::Succeeded, $generation->fresh()->status);
    }

    public function test_event_arriving_before_create_returns_is_matched_by_metadata(): void
    {
        $generation = $this->generation(); // pixelforge_id still null

        $this->deliver($this->event($generation, 'evt_1', 'generation.processing'))->assertNoContent();

        $generation->refresh();
        $this->assertSame('gen_abc', $generation->pixelforge_id);
        $this->assertSame(GenerationStatus::Processing, $generation->status);
    }

    public function test_failed_event_is_free(): void
    {
        $generation = $this->generation();

        $this->deliver($this->event($generation, 'evt_1', 'generation.failed', ['error' => ['code' => 'internal_error', 'message' => 'x']]))->assertNoContent();

        $this->assertSame(5_000_000_000, $generation->user->fresh()->balance_nanos);
        $this->assertSame(0, LedgerEntry::where('type', 'charge')->count());
    }

    public function test_ping_is_accepted(): void
    {
        $generation = $this->generation();

        $this->deliver(['id' => 'evt_p', 'object' => 'event', 'type' => 'ping', 'data' => ['object' => []]])->assertNoContent();
    }
}
