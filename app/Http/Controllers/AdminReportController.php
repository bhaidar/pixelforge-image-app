<?php

namespace App\Http\Controllers;

use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use App\Services\ProfitReport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class AdminReportController extends Controller
{
    public function __invoke(Request $request, ProfitReport $report, PixelforgeClient $client): Response
    {
        $data = $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start'],
        ]);

        $end = Carbon::parse($data['end'] ?? now('UTC')->toDateString(), 'UTC');
        $start = Carbon::parse($data['start'] ?? $end->copy()->subDays(29)->toDateString(), 'UTC');
        abort_if($start->diffInDays($end) > 91, 422, 'The date range can cover at most 92 days.');

        $result = $report->build($start, $end);

        return Inertia::render('admin/Report', [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            ...$result,
            'reconciliation' => $this->reconcile($client, $start, $end, $result['days']),
        ]);
    }

    /** Compare our per-day provider cost against what Pixelforge's /v1/usage says it billed. */
    private function reconcile(PixelforgeClient $client, Carbon $start, Carbon $end, array $ourDays): array
    {
        try {
            $usage = $client->usage($start->toDateString(), $end->toDateString());
        } catch (PixelforgeException $e) {
            return ['available' => false, 'error' => $e->getMessage(), 'days' => []];
        }

        $ours = array_column($ourDays, 'provider_nanos', 'date');

        $days = array_map(function (array $day) use ($ours) {
            $reported = $this->toNanos($day['cost_usd']);
            $local = $ours[$day['date']] ?? 0;

            return [
                'date' => $day['date'],
                'pixelforge_nanos' => $reported,
                'ours_nanos' => $local,
                'diff_nanos' => $local - $reported,
            ];
        }, $usage['data']);

        return ['available' => true, 'error' => null, 'days' => $days];
    }

    /** "0.046125000" => 46125000, without floats. */
    private function toNanos(string $usd): int
    {
        [$whole, $fraction] = array_pad(explode('.', $usd, 2), 2, '');

        return (int) $whole * 1_000_000_000 + (int) str_pad(substr($fraction, 0, 9), 9, '0');
    }
}
