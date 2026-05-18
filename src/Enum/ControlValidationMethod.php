<?php

declare(strict_types=1);

namespace App\Enum;

enum ControlValidationMethod: string
{
    case QrCode = 'qr_code';
    case Nfc = 'nfc';
    case IBeacon = 'ibeacon';
    case Uwb = 'uwb';
    case Gps = 'gps';
}
