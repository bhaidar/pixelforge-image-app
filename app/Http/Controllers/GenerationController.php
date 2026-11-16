<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Exceptions\InsufficientBalance;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Pixelforge\PricingCatalog;
use App\Services\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    private const SIZES = ['1024x1024', '1536x1024', '1024x1536'];

    private const QUALITIES = ['low', 'medium', 'high'];

    public function __construct(private readonly PricingCatalog $catalog, private readonly Wallet $wallet) {}

    public function index(Request $request): Response
    {
        return Inertia::render('generations/Index', [
            'generations' => $request->user()->generations()->latest('id')->limit(50)->get()->map(fn (Generation $g) => [
                'id' => $g->uuid,
                'prompt' => $g->prompt,
                'size' => $g->size,
                'quality' => $g->quality,
                'status' => $g->status->value,
                'estimated_min_nanos' => $g->estimated_min_nanos,
                'estimated_max_nanos' => $g->estimated_max_nanos,
                'charge_nanos' => $g->charge_nanos,
                'image_url' => $g->image_url_expires_at?->isFuture() ? $g->image_url : null,
                'image_expired' => $g->image_url_expires_at?->isPast() ?? false,
                'error_message' => $g->error_message,
                'created_at' => $g->created_at->toIso8601String(),
            ]),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('generations/Create', [
            'sizes' => self::SIZES,
            'qualities' => self::QUALITIES,
        ]);
    }

    public function estimate(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $estimate = $this->catalog->estimator()->estimate($data['prompt'], $data['size'], $data['quality'], count($data['reference_images'] ?? []));

        return response()->json([
            'min_nanos' => $estimate->minChargeNanos,
            'max_nanos' => $estimate->maxChargeNanos,
            'balance_nanos' => $request->user()->balance_nanos,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $references = $data['reference_images'] ?? [];
        $estimate = $this->catalog->estimator()->estimate($data['prompt'], $data['size'], $data['quality'], count($references));

        try {
            $generation = DB::transaction(function () use ($request, $data, $references, $estimate) {
                $generation = Generation::create([
                    'uuid' => (string) Str::uuid(),
                    'user_id' => $request->user()->id,
                    'status' => GenerationStatus::Pending,
                    'prompt' => $data['prompt'],
                    'size' => $data['size'],
                    'quality' => $data['quality'],
                    'reference_images' => $references,
                    'estimated_min_nanos' => $estimate->minChargeNanos,
                    'estimated_max_nanos' => $estimate->maxChargeNanos,
                ]);

                $this->wallet->hold($generation);

                return $generation;
            });
        } catch (InsufficientBalance) {
            return back()->withErrors(['prompt' => 'Not enough credit for this generation. Buy more credits to continue.']);
        }

        SubmitGeneration::dispatch($generation->id)->afterCommit();

        return to_route('generations.index');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::in(self::SIZES)],
            'quality' => ['required', Rule::in(self::QUALITIES)],
            'reference_images' => ['nullable', 'array', 'max:4'],
            'reference_images.*' => ['required', 'url:http,https', 'max:2000'],
        ]);
    }
}
