<?php

namespace App\Models;

use App\Enums\LedgerReason;
use Illuminate\Database\Eloquent\Model;

class CreditLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'credit_ledger';

    protected $fillable = ['user_id', 'delta', 'reason', 'creation_id', 'stylization_id'];

    protected function casts(): array
    {
        return ['reason' => LedgerReason::class];
    }
}
