<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Activity;
use App\Entity\Course;
use App\Enum\ActivityStatus;
use App\Enum\CourseType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Activity>
 */
class ActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Activity::class);
    }

    /**
     * Completed activities for a given course, in ranking order.
     *
     * - Score courses: highest total score first, ties broken by fastest time.
     * - Other courses: shortest duration first.
     *
     * @return list<Activity>
     */
    public function findRanking(Course $course): array
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.course = :course')
            ->andWhere('a.status = :completed')
            ->setParameter('course', $course)
            ->setParameter('completed', ActivityStatus::Completed->value);

        if (CourseType::Score === $course->getType()) {
            $qb->orderBy('a.totalScore', 'DESC')
                ->addOrderBy('a.totalDurationSec', 'ASC');
        } else {
            $qb->orderBy('a.totalDurationSec', 'ASC');
        }

        /** @var list<Activity> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }
}
