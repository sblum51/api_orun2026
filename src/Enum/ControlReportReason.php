<?php

declare(strict_types=1);

namespace App\Enum;

enum ControlReportReason: string
{
    case Missing = 'missing';   // Poste absent
    case Damaged = 'damaged';   // Poste abîmé
}
