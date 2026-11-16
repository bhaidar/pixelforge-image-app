<?php

namespace App\Http\Controllers;

use App\Models\WebhookEvent;
use App\Pixelforge\SignatureVerifier;
use App\Services\GenerationSettler;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, GenerationSettler $settler): Response
    {
        $verifier = new SignatureVerifier(
            (string) config('pixelforge.webhook_secret'),
            config('pixelforge.webhook_tolerance_seconds'),
        );

        // Verify against the raw body, before any JSON decoding.
        abort_unless($verifier->verify($request->getContent(), $request->header('Pixelforge-Signature')), 400, 'Invalid Pixelforge signature.');

        $event = $request->json()->all();

        DB::transaction(function () use ($event, $settler) {
            try {
                $record = WebhookEvent::create([
                    'event_id' => $event['id'],
                    'type' => $event['type'],
                    'payload' => $event,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Redelivery. Only reprocess if an earlier attempt crashed before finishing.
                $record = WebhookEvent::where('event_id', $event['id'])->first();

                if ($record->processed_at) {
                    return;
                }
            }

            if (str_starts_with($event['type'], 'generation.')) {
                $settler->apply($event['data']['object']);
            }

            $record->update(['processed_at' => now()]);
        });

        return response()->noContent();
    }
}
