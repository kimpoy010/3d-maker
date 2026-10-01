<?php

namespace App\Services\ModelProviders;

enum ProviderState
{
    case Pending;
    case Running;
    case Succeeded;
    case Failed;
}
