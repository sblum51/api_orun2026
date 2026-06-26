<?php

declare(strict_types=1);

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Control;
use App\Entity\Course;
use App\Entity\Event;
use App\Entity\OrganizationMember;
use App\Entity\User;
use App\Enum\Visibility;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Strips Private events / courses / controls from GetCollection results when the
 * caller isn't entitled to see them. Matches the per-item rule enforced by the
 * Event/Course/Control voters, but applied as a SQL filter so paginators stay
 * accurate.
 *
 * Rules:
 *  - Admin sees everything.
 *  - Anonymous / no user (defensive) sees only Public.
 *  - Authenticated user sees: Public items, plus items owned by an organization
 *    they're a member of, plus standalone events they created. For Course /
 *    Control the rule cascades through the parent Event.
 */
final readonly class VisibilityExtension implements QueryCollectionExtensionInterface
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
        if (!\in_array($resourceClass, [Event::class, Course::class, Control::class], true)) {
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

        $publicParam = $queryNameGenerator->generateParameterName('visibility_public');

        if (Event::class === $resourceClass) {
            $this->applyEventFilterOnAlias($queryBuilder, $queryNameGenerator, $rootAlias, $user, $publicParam);

            return;
        }

        $eventAlias = $queryNameGenerator->generateJoinAlias('event');
        $queryBuilder->innerJoin($rootAlias.'.event', $eventAlias);

        if (Course::class === $resourceClass) {
            $this->applyCourseFilter($queryBuilder, $queryNameGenerator, $rootAlias, $eventAlias, $user, $publicParam);

            return;
        }

        $this->applyEventFilterOnAlias($queryBuilder, $queryNameGenerator, $eventAlias, $user, $publicParam);
    }

    private function applyCourseFilter(
        QueryBuilder $qb,
        QueryNameGeneratorInterface $qng,
        string $courseAlias,
        string $eventAlias,
        ?object $user,
        string $publicParam,
    ): void {
        $qb->setParameter($publicParam, Visibility::Public->value);

        if (!$user instanceof User) {
            $qb->andWhere(sprintf(
                '%s.visibility = :%s AND %s.visibility = :%s',
                $courseAlias,
                $publicParam,
                $eventAlias,
                $publicParam,
            ));

            return;
        }

        $userParam = $qng->generateParameterName('current_user');
        $omAlias = $qng->generateJoinAlias('om');

        $qb->andWhere(sprintf(
            '(%s.visibility = :%s AND %s.visibility = :%s)'
            .' OR %s.organization IN (SELECT IDENTITY(%s.organization) FROM %s %s WHERE %s.user = :%s)'
            .' OR %s.creator = :%s',
            $courseAlias,
            $publicParam,
            $eventAlias,
            $publicParam,
            $eventAlias,
            $omAlias,
            OrganizationMember::class,
            $omAlias,
            $omAlias,
            $userParam,
            $eventAlias,
            $userParam,
        ));
        $qb->setParameter($userParam, $user->getId());
    }

    private function applyEventFilterOnAlias(
        QueryBuilder $qb,
        QueryNameGeneratorInterface $qng,
        string $eventAlias,
        ?object $user,
        string $publicParam,
    ): void {
        $qb->setParameter($publicParam, Visibility::Public->value);

        if (!$user instanceof User) {
            $qb->andWhere(sprintf('%s.visibility = :%s', $eventAlias, $publicParam));

            return;
        }

        $userParam = $qng->generateParameterName('current_user');
        $omAlias = $qng->generateJoinAlias('om');

        $qb->andWhere(sprintf(
            '%s.visibility = :%s'
            .' OR %s.organization IN (SELECT IDENTITY(%s.organization) FROM %s %s WHERE %s.user = :%s)'
            .' OR %s.creator = :%s',
            $eventAlias,
            $publicParam,
            $eventAlias,
            $omAlias,
            OrganizationMember::class,
            $omAlias,
            $omAlias,
            $userParam,
            $eventAlias,
            $userParam,
        ));
        $qb->setParameter($userParam, $user->getId());
    }
}
