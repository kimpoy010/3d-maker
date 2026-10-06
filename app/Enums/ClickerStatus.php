<?php

namespace App\Enums;

enum ClickerStatus: string
{
    case Draft = 'draft';
    case Building = 'building';
    case Ready = 'ready';
    case Failed = 'failed';
}
