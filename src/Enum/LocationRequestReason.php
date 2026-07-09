<?php

declare(strict_types=1);

namespace App\Enum;

enum LocationRequestReason: string
{
    case Lost = 'lost';
    case Injured = 'injured';
    case CheckIn = 'check_in';
    case Other = 'other';

    public function requiresFreeText(): bool
    {
        return self::Other === $this;
    }
}
