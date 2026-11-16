<?php

namespace App\Http\Controllers;

use App\Services\Wallet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreditController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('credits/Index', [
            'options' => config('pixelforge.purchase_options_usd'),
            'entries' => $request->user()->ledgerEntries()
                ->whereIn('type', ['grant', 'purchase'])
                ->latest('id')->limit(20)->get(['type', 'amount_nanos', 'created_at']),
        ]);
    }

    /** Fake purchase: no payment is taken. */
    public function buy(Request $request, Wallet $wallet): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'in:'.implode(',', config('pixelforge.purchase_options_usd'))],
        ]);

        $wallet->credit($request->user(), 'purchase', $data['amount'] * 1_000_000_000);

        return back();
    }
}
