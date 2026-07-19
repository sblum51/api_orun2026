<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Event;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Manager view — the queue of control reports for an event, joined
 * with control code + course + runner pseudo so a single scroll gives
 * enough context to act.
 *
 * Auth: `manage` on the event. Filterable by `?status=pending` to
 * focus on what needs attention.
 */
final class EventControlReportsController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    #[Route(
        path: '/api/events/{id}/control-reports',
        name: 'api_event_control_reports',
        methods: ['GET'],
        requirements: ['id' => '[0-9a-f-]{36}'],
        format: 'json',
    )]
    #[IsGranted('ROLE_USER')]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $event = $this->em->find(Event::class, $id);
        if ($event === null) {
            throw new NotFoundHttpException('Event not found.');
        }
        if (!$this->security->isGranted('manage', $event)) {
            throw new AccessDeniedHttpException(
                'You must manage this event to see control reports.',
            );
        }

        $statusFilter = $request->query->get('status');

        $sql = <<<'SQL'
            SELECT
                r.id AS report_id,
                r.reason AS reason,
                r.status AS status,
                r.comment AS comment,
                r.photo_url AS photo_url,
                r.created_at AS created_at,
                r.updated_at AS updated_at,
                r.acknowledged_at AS acknowledged_at,
                a.id AS activity_id,
                a.pseudo AS pseudo,
                c.id AS control_id,
                c.code AS control_code,
                co.name AS course_name,
                TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS user_name,
                TRIM(CONCAT(COALESCE(mu.first_name, ''), ' ', COALESCE(mu.last_name, ''))) AS ack_by_name
            FROM control_reports r
            INNER JOIN activities a ON a.id = r.activity_id
            INNER JOIN courses co ON co.id = a.course_id
            INNER JOIN controls c ON c.id = r.control_id
            LEFT JOIN users u ON u.id = a.user_id
            LEFT JOIN users mu ON mu.id = r.acknowledged_by_user_id
            WHERE co.event_id = :eventId
        SQL;
        $params = ['eventId' => $id];
        if (\is_string($statusFilter) && $statusFilter !== '') {
            $sql .= ' AND r.status = :status';
            $params['status'] = $statusFilter;
        }
        $sql .= ' ORDER BY r.created_at DESC';

        /** @var Connection $conn */
        $conn = $this->em->getConnection();
        $rows = $conn->executeQuery($sql, $params)->fetchAllAssociative();

        $out = [];
        $counts = ['pending' => 0, 'acknowledged' => 0, 'resolved' => 0, 'dismissed' => 0];
        foreach ($rows as $r) {
            $status = (string) $r['status'];
            if (isset($counts[$status])) {
                ++$counts[$status];
            }
            $out[] = [
                'id' => $r['report_id'],
                'reason' => $r['reason'],
                'status' => $status,
                'comment' => $r['comment'],
                'photoUrl' => $r['photo_url'],
                'createdAt' => (new \DateTimeImmutable((string) $r['created_at']))
                    ->format(\DateTimeInterface::ATOM),
                'updatedAt' => (new \DateTimeImmutable((string) $r['updated_at']))
                    ->format(\DateTimeInterface::ATOM),
                'acknowledgedAt' => $r['acknowledged_at']
                    ? (new \DateTimeImmutable((string) $r['acknowledged_at']))
                        ->format(\DateTimeInterface::ATOM)
                    : null,
                'acknowledgedByName' => !empty($r['ack_by_name']) ? $r['ack_by_name'] : null,
                'activityId' => $r['activity_id'],
                'controlId' => $r['control_id'],
                'controlIri' => sprintf('/api/controls/%s', $r['control_id']),
                'controlCode' => $r['control_code'],
                'courseName' => $r['course_name'],
                'reporter' => !empty($r['pseudo'])
                    ? $r['pseudo']
                    : (!empty($r['user_name']) ? $r['user_name'] : 'Anonyme'),
            ];
        }

        return new JsonResponse([
            'eventId' => $id,
            'counts' => $counts,
            'reports' => $out,
        ]);
    }
}
