<?php

namespace App\Services\Credits;

use App\Enums\LedgerReason;
use App\Exceptions\InsufficientCreditsException;
use App\Models\Creation;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class CreditService
{
    public function balance(User $user): int
    {
        return (int) CreditLedgerEntry::where('user_id', $user->id)->sum('delta');
    }

    public function grant(User $user, int $amount, LedgerReason $reason): CreditLedgerEntry
    {
        return CreditLedgerEntry::create([
            'user_id' => $user->id,
            'delta' => $amount,
            'reason' => $reason,
        ]);
    }

    /**
     * Charge a creation. Locks the user row so two concurrent submissions
     * cannot both pass the balance check.
     */
    public function spend(User $user, int $amount, Creation $creation): CreditLedgerEntry
    {
        return DB::transaction(function () use ($user, $amount, $creation) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $balance = $this->balance($user);
            if ($balance < $amount) {
                throw new InsufficientCreditsException($amount, $balance);
            }

            return CreditLedgerEntry::create([
                'user_id' => $user->id,
                'delta' => -$amount,
                'reason' => LedgerReason::Generation,
                'creation_id' => $creation->id,
            ]);
        });
    }

    /** Give back what a creation cost. Returns null if there is nothing to refund. */
    public function refund(Creation $creation): ?CreditLedgerEntry
    {
        $spent = CreditLedgerEntry::where('creation_id', $creation->id)
            ->where('reason', LedgerReason::Generation)
            ->first();

        if (! $spent) {
            return null;
        }

        try {
            return DB::transaction(function () use ($creation, $spent) {
                $alreadyRefunded = CreditLedgerEntry::where('creation_id', $creation->id)
                    ->where('reason', LedgerReason::Refund)
                    ->exists();

                if ($alreadyRefunded) {
                    return null;
                }

                return CreditLedgerEntry::create([
                    'user_id' => $spent->user_id,
                    'delta' => -$spent->delta,
                    'reason' => LedgerReason::Refund,
                    'creation_id' => $creation->id,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent refund won the race; the unique index kept it to one.
            return null;
        }
    }
}
