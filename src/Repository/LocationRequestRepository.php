<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Activity;
use App\Entity\LocationRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LocationRequest>
 */
class LocationRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LocationRequest::class);
    }

    /**
     * Latest N requests for an activity, most recent first. Used both
     * by the runner audit view and by the manager to answer "how many
     * times have we already pinged this runner today?".
     *
     * @return list<LocationRequest>
     */
    public function findRecentForActivity(Activity $activity, int $limit = 50): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.activity = :activity')
            ->setParameter('activity', $activity)
            ->orderBy('r.requestedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findLatestForActivity(Activity $activity): ?LocationRequest
    {
        $rows = $this->findRecentForActivity($activity, 1);
        return $rows[0] ?? null;
    }
}
