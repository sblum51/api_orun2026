<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\CourseControl;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<CourseControl>
 */
final class CourseControlFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return CourseControl::class;
    }

    protected function defaults(): array
    {
        return [
            // Callers are expected to override `course`, `control` and
            // `position` — the defaults exist just so the factory boots.
            'course' => CourseFactory::new(),
            'control' => ControlFactory::new(),
            'position' => 1,
        ];
    }
}
