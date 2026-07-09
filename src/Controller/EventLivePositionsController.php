<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Event;
use App\Enum\ActivityStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Feed the manager's event live map with every runner's last known
 * position across the event's activities. Poll-friendly (returns
 * absolute timestamps so the client renders "il y a Xs" itself).
 *
 * Auth : requires `manage` on the event. Prevents general public from
 * seeing every runner's coordinates on a Public event.
 */
final class EventLivePositionsController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    #[Route(
        path: '/api/events/{id}/live-positions',
        name: 'api_event_live_positions',
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
                'You must manage this event to see live runner positions.',
            );
        }

        /** @var Connection $conn */
        $conn = $this->em->getConnection();
        $rows = $conn->executeQuery(
            <<<'SQL'
            SELECT
                a.id AS activity_id,
                a.status AS status,
                a.pseudo AS pseudo,
                a.last_lat AS lat,
                a.last_lng AS lng,
                a.last_located_at AS located_at,
                c.id AS course_id,
                c.name AS course_name,
                TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS user_name
            FROM activities a
            INNER JOIN courses c ON c.id = a.course_id
            LEFT JOIN users u ON u.id = a.user_id
            WHERE c.event_id = :eventId
              AND a.last_located_at IS NOT NULL
            ORDER BY a.last_located_at DESC
            SQL,
            ['eventId' => $id],
        )->fetchAllAssociative();

        $out = [];
        foreach ($rows as $r) {
            $status = ActivityStatus::tryFrom((string) $r['status']) ?? ActivityStatus::Running;
            $out[] = [
                'activityId' => $r['activity_id'],
                'status' => $status->value,
                'pseudo' => !empty($r['pseudo']) ? $r['pseudo'] : (!empty($r['user_name']) ? $r['user_name'] : 'Anonyme'),
                'courseName' => $r['course_name'],
                'lat' => (float) $r['lat'],
                'lng' => (float) $r['lng'],
                'locatedAt' => (new \DateTimeImmutable((string) $r['located_at']))
                    ->format(\DateTimeInterface::ATOM),
            ];
        }

        return new JsonResponse([
            'eventId' => $id,
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'positions' => $out,
        ]);
    }
}
