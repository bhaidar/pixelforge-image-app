<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use App\Services\GenerationSettler;
use Illuminate\Console\Command;

class SyncGenerations extends Command
{
    protected $signature = 'generations:sync {--stale-seconds=60 : Only sync generations unchanged for this long}';

    protected $description = 'Poll Pixelforge for generations whose webhooks have not arrived, and resubmit stuck pending ones.';

    public function handle(PixelforgeClient $client, GenerationSettler $settler): int
    {
        $cutoff = now()->subSeconds((int) $this->option('stale-seconds'));

        Generation::query()
            ->whereIn('status', [GenerationStatus::Pending, GenerationStatus::Queued, GenerationStatus::Processing])
            ->where('updated_at', '<', $cutoff)
            ->each(function (Generation $generation) use ($client, $settler) {
                if (! $generation->pixelforge_id) {
                    // Idempotency-Key makes resubmitting safe even if the first request actually succeeded.
                    SubmitGeneration::dispatch($generation->id);
                    $generation->touch();

                    return;
                }

                try {
                    $settler->apply($client->getGeneration($generation->pixelforge_id));
                    $generation->touch();
                } catch (PixelforgeException $e) {
                    $this->warn("{$generation->pixelforge_id}: {$e->errorCode}");
                }
            });

        return self::SUCCESS;
    }
}
