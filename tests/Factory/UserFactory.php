<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\User;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<User>
 */
final class UserFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return User::class;
    }

    protected function defaults(): array
    {
        return [
            'email' => self::faker()->unique()->safeEmail(),
            'firstName' => self::faker()->firstName(),
            'lastName' => self::faker()->lastName(),
            // CRUD tests authenticate by minting a JWT for the user directly,
            // so a placeholder password hash is sufficient here.
            'password' => '$2y$13$placeholderplaceholderplaceholderplaceholderpl',
            'roles' => [],
        ];
    }
}
