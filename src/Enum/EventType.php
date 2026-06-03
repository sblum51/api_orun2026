<?php

declare(strict_types=1);

namespace App\Enum;

enum EventType: string
{
    case Permanent = 'permanent';
    case Temporal = 'temporal';
    case Seasonal = 'seasonal';
}
