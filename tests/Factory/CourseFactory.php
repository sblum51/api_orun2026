<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Course;
use App\Enum\CourseType;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Course>
 */
final class CourseFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Course::class;
    }

    protected function defaults(): array
    {
        return [
            'name' => self::faker()->unique()->words(2, true),
            'type' => self::faker()->randomElement(CourseType::cases()),
            'event' => EventFactory::new(),
            'durationLimitMin' => self::faker()->optional()->numberBetween(30, 180),
            'distanceKm' => (string) self::faker()->randomFloat(2, 1, 15),
        ];
    }
}
