<?php

declare(strict_types=1);

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\OrganizationMember;
use App\Entity\Tag;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Filters /api/tags collections to ones the caller can actually manage:
 *  - tags they created themselves (personal library)
 *  - tags filed under an organization they belong to
 *
 * Without this, GET /api/tags would leak every manager's NFC inventory
 * across the platform. Admin gets the unfiltered firehose.
 */
final readonly class TagOwnershipExtension implements QueryCollectionExtensionInterface
{
    public function __construct(
        private Security $security,
    ) {
    }

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if (Tag::class !== $resourceClass) {
            return;
        }
        $user = $this->security->getUser();
        if ($user instanceof User && \in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0] ?? null;
        if (null === $rootAlias) {
            return;
        }
        if (!$user instanceof User) {
            // Anonymous shouldn't happen on this endpoint (it's IS_GRANTED('ROLE_USER'))
            // but be defensive: hide everything.
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $userParam = $queryNameGenerator->generateParameterName('current_user');
        $omAlias = $queryNameGenerator->generateJoinAlias('om');

        $queryBuilder->andWhere(sprintf(
            '%s.creator = :%s'
            .' OR %s.organization IN (SELECT IDENTITY(%s.organization) FROM %s %s WHERE %s.user = :%s)',
            $rootAlias,
            $userParam,
            $rootAlias,
            $omAlias,
            OrganizationMember::class,
            $omAlias,
            $omAlias,
            $userParam,
        ));
        $queryBuilder->setParameter($userParam, $user->getId());
    }
}
