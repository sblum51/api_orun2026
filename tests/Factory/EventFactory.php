<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Event;
use App\Entity\User;
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
            'organization' => OrganizationFactory::new(),
            'creator' => UserFactory::new(),
            'startDate' => \DateTimeImmutable::createFromMutable($start),
            'endDate' => \DateTimeImmutable::createFromMutable($end),
            'description' => self::faker()->optional()->sentence(),
            'location' => self::faker()->optional()->city(),
            'published' => self::faker()->boolean(70),
        ];
    }

    public function withCreator(User $user): static
    {
        return $this->with(['creator' => $user]);
    }

    public function standalone(User $creator): static
    {
        return $this->with(['organization' => null, 'creator' => $creator]);
    }
}
