<?php

namespace App\Enums;

enum LedgerReason: string
{
    case Signup = 'signup';
    case Topup = 'topup';
    case Generation = 'generation';
    case Refund = 'refund';
    case Stylize = 'stylize';
    case StylizeRefund = 'stylize_refund';
}
