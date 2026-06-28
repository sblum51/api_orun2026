<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Role of a Control within its parent Event. The default `Control` is the
 * numbered orienteering station (code 31-400). `Start` and `Finish` are
 * special: they don't carry a numeric code, they're typically the same
 * physical spot for many courses on the same event, and they live on the
 * same entity so the operator can stamp tags (NFC/QR/iBeacon) on them and
 * the app can validate them the same way as any regular control.
 *
 * Historical note: previous iterations stored start/finish only as
 * coordinates on Course (`starts[]` / `finishes[]` JSON arrays). That
 * model stays in place for back-compat — both shapes can coexist; the
 * mobile run engine looks at Control rows first, then falls back to the
 * arrays for courses that haven't been migrated.
 */
enum ControlType: string
{
    case Control = 'control';
    case Start = 'start';
    case Finish = 'finish';

    public function label(): string
    {
        return match ($this) {
            self::Control => 'Poste',
            self::Start => 'Départ',
            self::Finish => 'Arrivée',
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
