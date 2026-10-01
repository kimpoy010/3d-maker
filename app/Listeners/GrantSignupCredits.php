<?php

namespace App\Listeners;

use App\Enums\LedgerReason;
use App\Services\Credits\CreditService;
use Illuminate\Auth\Events\Registered;

class GrantSignupCredits
{
    public function __construct(private CreditService $credits) {}

    public function handle(Registered $event): void
    {
        $this->credits->grant($event->user, (int) config('credits.signup'), LedgerReason::Signup);
    }
}
