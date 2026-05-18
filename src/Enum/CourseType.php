<?php

declare(strict_types=1);

namespace App\Enum;

enum CourseType: string
{
    case Classic = 'classic';
    case Score = 'score';
    case SharedRelay = 'shared_relay';
    case Tourist = 'tourist';
}
