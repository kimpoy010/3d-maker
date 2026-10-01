<?php

namespace App\Enums;

enum LedgerReason: string
{
    case Signup = 'signup';
    case Topup = 'topup';
    case Generation = 'generation';
    case Refund = 'refund';
}
