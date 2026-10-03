<?php

namespace App\Support;

final class Money
{
    /** The app's balance is kept in credits where 1 credit = 1 peso, shown to customers as pesos. */
    public static function peso(int $credits): string
    {
        return '₱'.number_format($credits);
    }
}
