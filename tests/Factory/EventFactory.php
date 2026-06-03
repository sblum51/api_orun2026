<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Event;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Event>
 */
final class EventFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Event::class;
    }

    protected function defaults(): array
    {
        $start = self::faker()->dateTimeBetween('+1 week', '+3 months');
        $end = (clone $start)->modify('+'.self::faker()->numberBetween(1, 5).' days');

        return [
            'name' => self::faker()->unique()->sentence(3),
            'slug' => self::faker()->unique()->slug(),
            'organization' => OrganizationFactory::new(),
            'startDate' => \DateTimeImmutable::createFromMutable($start),
            'endDate' => \DateTimeImmutable::createFromMutable($end),
            'description' => self::faker()->optional()->sentence(),
            'location' => self::faker()->optional()->city(),
            'published' => self::faker()->boolean(70),
        ];
    }
}
