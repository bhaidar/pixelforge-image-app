<?php

namespace Tests\Feature;

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GenerationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake(['*/pricing' => Http::response([
            'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
            'tokens' => [
                'characters_per_text_token' => 4, 'text_token_variance' => 0.15, 'per_reference_image' => 765,
                'output' => ['low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420], 'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650], 'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450]],
                'reasoning' => ['low' => ['min' => 0, 'max' => 500], 'medium' => ['min' => 200, 'max' => 2000], 'high' => ['min' => 1000, 'max' => 8000]],
            ],
        ])]);
    }

    private function userWithCredit(int $nanos): User
    {
        $user = User::factory()->create();
        app(Wallet::class)->credit($user, 'grant', $nanos);

        return $user;
    }

    public function test_estimate_returns_charge_range_with_markup(): void
    {
        $user = $this->userWithCredit(5_000_000_000);

        $this->actingAs($user)->postJson('/generations/estimate', [
            'prompt' => str_repeat('a', 400), 'size' => '1536x1024', 'quality' => 'high', 'reference_images' => ['https://a.test/1.png', 'https://a.test/2.png'],
        ])->assertOk()->assertJson(['max_nanos' => 467_343_750]);
    }

    public function test_store_holds_the_max_estimate_and_dispatches(): void
    {
        Queue::fake();
        $user = $this->userWithCredit(5_000_000_000);

        $this->actingAs($user)->post('/generations', ['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'low'])
            ->assertRedirect('/generations');

        $generation = Generation::firstOrFail();
        $this->assertSame(5_000_000_000 - $generation->estimated_max_nanos, $user->fresh()->balance_nanos);
        Queue::assertPushed(SubmitGeneration::class);
    }

    public function test_store_is_blocked_when_user_cannot_afford_it(): void
    {
        Queue::fake();
        $user = $this->userWithCredit(1_000);

        $this->actingAs($user)->post('/generations', ['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'low'])
            ->assertSessionHasErrors('prompt');

        $this->assertSame(0, Generation::count());
        $this->assertSame(1_000, $user->fresh()->balance_nanos);
        Queue::assertNothingPushed();
    }

    public function test_validation_rejects_bad_input(): void
    {
        $user = $this->userWithCredit(5_000_000_000);

        $this->actingAs($user)->post('/generations', ['prompt' => '', 'size' => '1x1', 'quality' => 'ultra', 'reference_images' => ['not a url']])
            ->assertSessionHasErrors(['prompt', 'size', 'quality', 'reference_images.0']);
    }

    public function test_history_only_shows_own_generations(): void
    {
        $mine = $this->userWithCredit(5_000_000_000);
        $other = $this->userWithCredit(5_000_000_000);
        Queue::fake();
        $this->actingAs($other)->post('/generations', ['prompt' => 'secret', 'size' => '1024x1024', 'quality' => 'low']);

        $this->actingAs($mine)->get('/generations')->assertInertia(fn ($page) => $page->has('generations', 0));
    }

    public function test_buy_credits_adds_to_balance(): void
    {
        $user = $this->userWithCredit(0);

        $this->actingAs($user)->post('/credits', ['amount' => 10])->assertRedirect();

        $this->assertSame(10_000_000_000, $user->fresh()->balance_nanos);
    }

    public function test_buy_credits_rejects_unlisted_amounts(): void
    {
        $user = $this->userWithCredit(0);

        $this->actingAs($user)->post('/credits', ['amount' => 9999])->assertSessionHasErrors('amount');
    }

    public function test_admin_report_is_admin_only(): void
    {
        $this->actingAs($this->userWithCredit(0))->get('/admin/report')->assertForbidden();
    }
}
