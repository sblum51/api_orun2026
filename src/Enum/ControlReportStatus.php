<?php

declare(strict_types=1);

namespace App\Enum;

enum ControlReportStatus: string
{
    case Pending = 'pending';           // Nouveau signalement, action attendue
    case Acknowledged = 'acknowledged'; // Manager en a pris connaissance / traite
    case Resolved = 'resolved';         // Poste remis en état / remplacé
    case Dismissed = 'dismissed';       // Faux positif, ignoré
}
