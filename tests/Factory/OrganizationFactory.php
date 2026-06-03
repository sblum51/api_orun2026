<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Organization;
use App\Entity\User;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Organization>
 */
final class OrganizationFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Organization::class;
    }

    protected function defaults(): array
    {
        return [
            'name' => self::faker()->unique()->company(),
            'description' => self::faker()->optional()->sentence(),
        ];
    }

    protected function initialize(): static
    {
        return $this->afterInstantiate(function (Organization $organization): void {
            // Every organization must have at least one member; if the caller
            // didn't add one via withMember(), create a default user.
            if ($organization->getMembers()->isEmpty()) {
                $organization->addMember(UserFactory::createOne());
            }
        });
    }

    public function withMember(User $user): static
    {
        return $this->afterInstantiate(
            fn (Organization $organization) => $organization->addMember($user),
        );
    }
}
