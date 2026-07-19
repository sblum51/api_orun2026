<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Event;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Manager view — every feedback submitted on an event, with the
 * runner's display pseudo (or account name fallback) and a snapshot
 * of the run's course/status so the manager can filter mentally.
 *
 * Also returns aggregate stats (count + average) as a header block so
 * the UI can render a summary without post-processing.
 *
 * Auth: `manage` on the event. Feedbacks are considered internal
 * intel for the operator — public visibility would need a separate
 * curated endpoint.
 */
final class EventFeedbacksController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    #[Route(
        path: '/api/events/{id}/feedbacks',
        name: 'api_event_feedbacks',
        methods: ['GET'],
        requirements: ['id' => '[0-9a-f-]{36}'],
        format: 'json',
    )]
    #[IsGranted('ROLE_USER')]
    public function __invoke(string $id): JsonResponse
    {
        $event = $this->em->find(Event::class, $id);
        if ($event === null) {
            throw new NotFoundHttpException('Event not found.');
        }
        if (!$this->security->isGranted('manage', $event)) {
            throw new AccessDeniedHttpException(
                'You must manage this event to see its feedbacks.',
            );
        }

        /** @var Connection $conn */
        $conn = $this->em->getConnection();
        $rows = $conn->executeQuery(
            <<<'SQL'
            SELECT
                f.id AS feedback_id,
                f.rating AS rating,
                f.comment AS comment,
                f.created_at AS created_at,
                f.updated_at AS updated_at,
                a.id AS activity_id,
                a.pseudo AS pseudo,
                a.status AS activity_status,
                c.id AS course_id,
                c.name AS course_name,
                TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS user_name
            FROM feedbacks f
            INNER JOIN activities a ON a.id = f.activity_id
            INNER JOIN courses c ON c.id = a.course_id
            LEFT JOIN users u ON u.id = a.user_id
            WHERE c.event_id = :eventId
            ORDER BY f.created_at DESC
            SQL,
            ['eventId' => $id],
        )->fetchAllAssociative();

        $total = 0;
        $sum = 0;
        $out = [];
        foreach ($rows as $r) {
            $rating = (int) $r['rating'];
            ++$total;
            $sum += $rating;
            $out[] = [
                'id' => $r['feedback_id'],
                'rating' => $rating,
                'comment' => $r['comment'],
                'createdAt' => (new \DateTimeImmutable((string) $r['created_at']))
                    ->format(\DateTimeInterface::ATOM),
                'updatedAt' => (new \DateTimeImmutable((string) $r['updated_at']))
                    ->format(\DateTimeInterface::ATOM),
                'activityId' => $r['activity_id'],
                'activityStatus' => $r['activity_status'],
                'courseName' => $r['course_name'],
                'pseudo' => !empty($r['pseudo'])
                    ? $r['pseudo']
                    : (!empty($r['user_name']) ? $r['user_name'] : 'Anonyme'),
            ];
        }

        return new JsonResponse([
            'eventId' => $id,
            'count' => $total,
            'average' => $total > 0 ? round($sum / $total, 2) : null,
            'feedbacks' => $out,
        ]);
    }
}
