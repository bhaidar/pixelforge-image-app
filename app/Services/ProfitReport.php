<?php

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Support\Carbon;

/** Per-day and per-user credit spend, provider cost and profit, bucketed by the UTC day a generation completed. */
class ProfitReport
{
    /**
     * @return array{rows: list<array>, days: list<array>, totals: array}
     */
    public function build(Carbon $start, Carbon $end): array
    {
        $generations = Generation::query()
            ->with('user:id,name,email')
            ->where('status', GenerationStatus::Succeeded)
            ->whereBetween('completed_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->get(['id', 'user_id', 'completed_at', 'charge_nanos', 'provider_cost_nanos']);

        $rows = [];
        $days = [];

        foreach ($generations as $g) {
            $day = $g->completed_at->copy()->utc()->toDateString();
            $key = $day.'|'.$g->user_id;

            $rows[$key] ??= ['date' => $day, 'user' => $g->user->name.' <'.$g->user->email.'>', 'generations' => 0, 'charged_nanos' => 0, 'provider_nanos' => 0];
            $days[$day] ??= ['date' => $day, 'generations' => 0, 'charged_nanos' => 0, 'provider_nanos' => 0];

            foreach ([&$rows[$key], &$days[$day]] as &$bucket) {
                $bucket['generations']++;
                $bucket['charged_nanos'] += $g->charge_nanos;
                $bucket['provider_nanos'] += $g->provider_cost_nanos;
            }
            unset($bucket);
        }

        krsort($days);
        $rows = array_values($rows);
        usort($rows, fn ($a, $b) => [$b['date'], $a['user']] <=> [$a['date'], $b['user']]);

        return [
            'rows' => array_map($this->withProfit(...), $rows),
            'days' => array_map($this->withProfit(...), array_values($days)),
            'totals' => $this->withProfit([
                'generations' => array_sum(array_column($rows, 'generations')),
                'charged_nanos' => array_sum(array_column($rows, 'charged_nanos')),
                'provider_nanos' => array_sum(array_column($rows, 'provider_nanos')),
            ]),
        ];
    }

    private function withProfit(array $row): array
    {
        return $row + ['profit_nanos' => $row['charged_nanos'] - $row['provider_nanos']];
    }
}
