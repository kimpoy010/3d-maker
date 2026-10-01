<?php

namespace App\Http\Controllers;

use App\Enums\LedgerReason;
use App\Models\CreditLedgerEntry;
use App\Services\Credits\CreditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreditController extends Controller
{
    public function index(Request $request, CreditService $credits): Response
    {
        $user = $request->user();

        return Inertia::render('Credits', [
            'balance' => $credits->balance($user),
            'ledger' => CreditLedgerEntry::where('user_id', $user->id)
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (CreditLedgerEntry $e) => [
                    'id' => $e->id,
                    'delta' => $e->delta,
                    'reason' => $e->reason->value,
                    'created_at' => $e->created_at->toIso8601String(),
                ])->values(),
            'topup' => [
                'enabled' => (bool) config('credits.stub_topup'),
                'amount' => (int) config('credits.topup'),
            ],
        ]);
    }

    /** Stub: real payments arrive in a later sub-project. */
    public function topup(Request $request, CreditService $credits): RedirectResponse
    {
        abort_unless(config('credits.stub_topup'), 404);

        $credits->grant($request->user(), (int) config('credits.topup'), LedgerReason::Topup);

        return back();
    }
}
