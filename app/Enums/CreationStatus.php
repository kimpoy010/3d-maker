<?php

namespace App\Enums;

enum CreationStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed], true);
    }
}
