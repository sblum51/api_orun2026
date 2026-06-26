<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Control;
use App\Enum\ControlValidationMethod;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Control>
 */
final class ControlFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Control::class;
    }

    protected function defaults(): array
    {
        return [
            'event' => EventFactory::new(),
            'code' => self::faker()->unique()->numberBetween(31, 400),
            'validationMethods' => [ControlValidationMethod::QrCode->value],
            'payload' => [],
            'latitude' => self::faker()->latitude(),
            'longitude' => self::faker()->longitude(),
        ];
    }
}
