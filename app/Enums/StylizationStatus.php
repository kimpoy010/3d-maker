<?php

namespace App\Enums;

enum StylizationStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Ready = 'ready';
    case Approved = 'approved';
    case Failed = 'failed';
    case Discarded = 'discarded';

    /** Still being produced by the restyle job. */
    public function isWorking(): bool
    {
        return in_array($this, [self::Queued, self::Processing], true);
    }

    /** Nothing more will ever happen to it. `Ready` waits for the customer and is not terminal. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Approved, self::Failed, self::Discarded], true);
    }
}
