<?php

namespace App\Services\Credits;

use App\Enums\LedgerReason;
use App\Exceptions\InsufficientCreditsException;
use App\Models\Clicker;
use App\Models\Creation;
use App\Models\CreditLedgerEntry;
use App\Models\Stylization;
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
        return $this->charge($user, $amount, LedgerReason::Generation, ['creation_id' => $creation->id]);
    }

    /** Charge a restyle preview. Same locking and balance check as {@see spend()}. */
    public function spendForStylization(User $user, int $amount, Stylization $stylization): CreditLedgerEntry
    {
        return $this->charge($user, $amount, LedgerReason::Stylize, ['stylization_id' => $stylization->id]);
    }

    /** Charge the one-time download unlock for a creation. The ledger's unique index allows one per creation. */
    public function spendForDownload(User $user, int $amount, Creation $creation): CreditLedgerEntry
    {
        return $this->charge($user, $amount, LedgerReason::Download, ['creation_id' => $creation->id]);
    }

    /** Charge one numbered attempt to unlock a clicker's downloads. */
    public function spendForClicker(User $user, int $amount, Clicker $clicker, int $attempt): CreditLedgerEntry
    {
        return $this->charge($user, $amount, LedgerReason::ClickerDownload, ['clicker_id' => $clicker->id, 'clicker_attempt' => $attempt]);
    }

    /**
     * Give back one numbered attempt. Returns null when that attempt was never charged or was already
     * refunded, so a retry after a refund (attempt 2) is never confused with attempt 1.
     */
    public function refundClicker(Clicker $clicker, int $attempt): ?CreditLedgerEntry
    {
        $scope = fn (LedgerReason $reason) => CreditLedgerEntry::where('clicker_id', $clicker->id)
            ->where('clicker_attempt', $attempt)
            ->where('reason', $reason);

        $spent = $scope(LedgerReason::ClickerDownload)->first();

        if (! $spent) {
            return null;
        }

        try {
            return DB::transaction(function () use ($spent, $clicker, $attempt, $scope) {
                if ($scope(LedgerReason::ClickerRefund)->exists()) {
                    return null;
                }

                return CreditLedgerEntry::create([
                    'user_id' => $spent->user_id,
                    'delta' => -$spent->delta,
                    'reason' => LedgerReason::ClickerRefund,
                    'clicker_id' => $clicker->id,
                    'clicker_attempt' => $attempt,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent refund won the race; the unique index kept it to one.
            return null;
        }
    }

    /** Give back what a creation cost. Returns null if there is nothing to refund. */
    public function refund(Creation $creation): ?CreditLedgerEntry
    {
        return $this->giveBack(LedgerReason::Generation, LedgerReason::Refund, 'creation_id', $creation->id);
    }

    /** Give back what a restyle preview cost. Returns null if there is nothing to refund. */
    public function refundStylization(Stylization $stylization): ?CreditLedgerEntry
    {
        return $this->giveBack(LedgerReason::Stylize, LedgerReason::StylizeRefund, 'stylization_id', $stylization->id);
    }

    /** @param array<string, int> $reference the ledger column linking the row to what was bought */
    private function charge(User $user, int $amount, LedgerReason $reason, array $reference): CreditLedgerEntry
    {
        return DB::transaction(function () use ($user, $amount, $reason, $reference) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $balance = $this->balance($user);
            if ($balance < $amount) {
                throw new InsufficientCreditsException($amount, $balance);
            }

            return CreditLedgerEntry::create([
                'user_id' => $user->id,
                'delta' => -$amount,
                'reason' => $reason,
            ] + $reference);
        });
    }

    private function giveBack(LedgerReason $charged, LedgerReason $refunded, string $column, int $id): ?CreditLedgerEntry
    {
        $spent = CreditLedgerEntry::where($column, $id)->where('reason', $charged)->first();

        if (! $spent) {
            return null;
        }

        try {
            return DB::transaction(function () use ($spent, $refunded, $column, $id) {
                $alreadyRefunded = CreditLedgerEntry::where($column, $id)->where('reason', $refunded)->exists();

                if ($alreadyRefunded) {
                    return null;
                }

                return CreditLedgerEntry::create([
                    'user_id' => $spent->user_id,
                    'delta' => -$spent->delta,
                    'reason' => $refunded,
                    $column => $id,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent refund won the race; the unique index kept it to one.
            return null;
        }
    }
}
