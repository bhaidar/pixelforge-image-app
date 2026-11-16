<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Services\ProfitReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfitReportTest extends TestCase
{
    use RefreshDatabase;

    private function generation(User $user, GenerationStatus $status, ?string $completedAt, int $cost, int $charge): void
    {
        Generation::create([
            'uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'status' => $status,
            'prompt' => 'p', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => [],
            'estimated_min_nanos' => 0, 'estimated_max_nanos' => 0,
            'provider_cost_nanos' => $cost, 'charge_nanos' => $charge,
            'completed_at' => $completedAt,
        ]);
    }

    public function test_groups_by_utc_completed_day_and_user_and_excludes_failed(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        $this->generation($a, GenerationStatus::Succeeded, '2026-11-01 23:59:59', 1_000, 1_250);
        $this->generation($a, GenerationStatus::Succeeded, '2026-11-02 00:00:01', 2_000, 2_500);
        $this->generation($b, GenerationStatus::Succeeded, '2026-11-02 10:00:00', 4_000, 5_000);
        $this->generation($b, GenerationStatus::Failed, '2026-11-02 11:00:00', 0, 0);

        $report = app(ProfitReport::class)->build(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-02'));

        $this->assertCount(3, $report['rows']);
        $this->assertSame(['2026-11-02', '2026-11-01'], array_column($report['days'], 'date'));
        $this->assertSame(6_000, $report['days'][0]['provider_nanos']);
        $this->assertSame(7_500, $report['days'][0]['charged_nanos']);
        $this->assertSame(1_500, $report['days'][0]['profit_nanos']);
        $this->assertSame(1_250 + 2_500 + 5_000, $report['totals']['charged_nanos']);
        $this->assertSame(3, $report['totals']['generations']);
    }
}
