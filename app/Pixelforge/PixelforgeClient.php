<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class PixelforgeClient
{
    private function http(): PendingRequest
    {
        return Http::baseUrl(config('pixelforge.base_url'))
            ->withToken((string) config('pixelforge.api_key'))
            ->acceptJson()
            ->timeout(15);
    }

    /** @param array<string, mixed> $params */
    public function createGeneration(array $params, string $idempotencyKey): array
    {
        return $this->send(fn () => $this->http()->withHeaders(['Idempotency-Key' => $idempotencyKey])->post('generations', $params));
    }

    public function getGeneration(string $id): array
    {
        return $this->send(fn () => $this->http()->get("generations/{$id}"));
    }

    public function pricing(): array
    {
        return Http::baseUrl(config('pixelforge.base_url'))->acceptJson()->timeout(10)->get('pricing')->throw()->json();
    }

    public function usage(string $startDate, string $endDate): array
    {
        return $this->send(fn () => $this->http()->get('usage', ['start_date' => $startDate, 'end_date' => $endDate]));
    }

    private function send(callable $request): array
    {
        try {
            /** @var Response $response */
            $response = $request();
        } catch (ConnectionException $e) {
            throw new PixelforgeException('Could not reach Pixelforge.', 0, 'connection_error', 2);
        }

        if ($response->successful()) {
            return $response->json();
        }

        $error = $response->json('error') ?? [];

        throw new PixelforgeException(
            $error['message'] ?? 'Pixelforge request failed.',
            $response->status(),
            $error['code'] ?? 'unknown_error',
            $response->header('Retry-After') !== '' ? (int) $response->header('Retry-After') : null,
        );
    }
}
