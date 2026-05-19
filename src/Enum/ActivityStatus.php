<?php

declare(strict_types=1);

namespace App\Enum;

enum ActivityStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Abandoned = 'abandoned';
}
