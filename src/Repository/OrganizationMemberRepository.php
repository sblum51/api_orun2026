<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\OrganizationMember;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrganizationMember>
 */
class OrganizationMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationMember::class);
    }

    public function isUserMemberOf(User $user, Organization $organization): bool
    {
        return null !== $this->findOneBy([
            'user' => $user,
            'organization' => $organization,
        ]);
    }

    public function countByOrganization(Organization $organization): int
    {
        return (int) $this->count(['organization' => $organization]);
    }

    public function hasAnyMembership(User $user): bool
    {
        return null !== $this->findOneBy(['user' => $user]);
    }
}
