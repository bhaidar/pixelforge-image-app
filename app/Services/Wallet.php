<?php

namespace App\Services;

use App\Exceptions\InsufficientBalance;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * All balance changes go through here. The ledger is append-only and signed;
 * users.balance_nanos is its running sum. (generation_id, type) is unique, so
 * a generation can be held, released and charged at most once each.
 */
class Wallet
{
    /** Add credit that is not tied to a generation (grant or purchase). */
    public function credit(User $user, string $type, int $nanos): void
    {
        DB::transaction(fn () => $this->post($user, null, $type, $nanos));
    }

    /** Reserve the maximum possible charge so a user can never spend more than they have. */
    public function hold(Generation $generation): void
    {
        DB::transaction(function () use ($generation) {
            $user = User::query()->lockForUpdate()->findOrFail($generation->user_id);
            $amount = $generation->estimated_max_nanos;

            if ($user->balance_nanos < $amount) {
                throw new InsufficientBalance($amount, $user->balance_nanos);
            }

            $this->post($user, $generation, 'hold', -$amount);
        });
    }

    /** Give back the hold and, for a succeeded generation, post the actual charge. Safe to call repeatedly. */
    public function settle(Generation $generation, int $chargeNanos): void
    {
        DB::transaction(function () use ($generation, $chargeNanos) {
            $user = User::query()->lockForUpdate()->findOrFail($generation->user_id);
            $hold = LedgerEntry::where('generation_id', $generation->id)->where('type', 'hold')->first();

            if ($hold) {
                $this->post($user, $generation, 'release', -$hold->amount_nanos);
            }

            if ($chargeNanos > 0) {
                $this->post($user, $generation, 'charge', -$chargeNanos);
            }
        });
    }

    private function post(User $user, ?Generation $generation, string $type, int $amount): void
    {
        if ($generation && LedgerEntry::where('generation_id', $generation->id)->where('type', $type)->exists()) {
            return;
        }

        LedgerEntry::create([
            'user_id' => $user->id,
            'generation_id' => $generation?->id,
            'type' => $type,
            'amount_nanos' => $amount,
        ]);

        $user->increment('balance_nanos', $amount);
    }
}
