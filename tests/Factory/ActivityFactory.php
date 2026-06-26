<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Activity;
use App\Enum\ActivityStatus;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Activity>
 */
final class ActivityFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Activity::class;
    }

    protected function defaults(): array
    {
        $startedAt = \DateTimeImmutable::createFromMutable(
            self::faker()->dateTimeBetween('-1 month', '-1 day'),
        );
        $finishedAt = $startedAt->modify('+'.self::faker()->numberBetween(15, 180).' minutes');

        return [
            'user' => UserFactory::new(),
            'course' => CourseFactory::new(),
            'startedAt' => $startedAt,
            'status' => ActivityStatus::Completed,
            'finishedAt' => $finishedAt,
            'totalDurationSec' => $finishedAt->getTimestamp() - $startedAt->getTimestamp(),
        ];
    }
}
