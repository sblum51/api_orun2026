<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How a runner can prove they reached a control. A given control can declare
 * several methods at once (e.g. QR sticker + NFC tag on the same stake, with
 * GPS as a fallback when the runner can't physically scan).
 */
enum ControlValidationMethod: string
{
    case QrCode = 'qr_code';
    case Nfc = 'nfc';
    case IBeacon = 'ibeacon';
    case Gps = 'gps';

    /**
     * Human-readable French label shown in the manager UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::QrCode => 'QR Code',
            self::Nfc => 'NFC',
            self::IBeacon => 'iBeacon',
            self::Gps => 'GPS',
        };
    }

    /**
     * Hint about the hardware the runner needs. Used in tooltips.
     */
    public function description(): string
    {
        return match ($this) {
            self::QrCode => 'Scan d\'un QR sticker posé sur le poste.',
            self::Nfc => 'Tag NFC à approcher du téléphone.',
            self::IBeacon => 'Balise iBeacon Bluetooth (validation automatique).',
            self::Gps => 'Validation par proximité GPS (cercle autour du poste).',
        };
    }

    /**
     * Lucide icon name used to render the method as a small chip.
     */
    public function icon(): string
    {
        return match ($this) {
            self::QrCode => 'qr-code',
            self::Nfc => 'wifi',
            self::IBeacon => 'bluetooth',
            self::Gps => 'crosshair',
        };
    }

    /**
     * Tailwind-friendly hex used to colour the chip in the manager.
     */
    public function color(): string
    {
        return match ($this) {
            self::QrCode => '#0EA5E9', // sky-500
            self::Nfc => '#10B981',    // emerald-500
            self::IBeacon => '#8B5CF6', // violet-500
            self::Gps => '#F59E0B',    // amber-500
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
