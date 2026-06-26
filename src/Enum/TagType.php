<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Physical-tag families managed in the operator's library. GPS is
 * intentionally excluded — a GPS validation has no physical tag to manage,
 * it's a geofence around the control's coordinates.
 */
enum TagType: string
{
    case QrCode = 'qr_code';
    case Nfc = 'nfc';
    case IBeacon = 'ibeacon';

    public function label(): string
    {
        return match ($this) {
            self::QrCode => 'QR Code',
            self::Nfc => 'NFC',
            self::IBeacon => 'iBeacon',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }
}
