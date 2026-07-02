<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Event;
use App\Enum\ActivityStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Live rankings for an event, one bucket per course.
 *
 * Everything is public to any logged-in user (no `manage` check): a
 * runner mid-course must be able to see how they stack up against
 * others, and the manager view shares the same endpoint.
 *
 * Response:
 *   {
 *     "eventId": "...",
 *     "eventName": "...",
 *     "generatedAt": "2026-07-02T13:00:00Z",
 *     "courses": [
 *       {
 *         "iri": "/api/courses/xxx",
 *         "name": "Bleu",
 *         "controlsCount": 12,   // total controls required (excl. start/finish)
 *         "entries": [
 *           { "activityId": "...", "pseudo": "Simon", "punchCount": 8,
 *             "elapsedSec": 1834, "status": "running", "startedAt": "..." }
 *         ]
 *       }
 *     ]
 *   }
 *
 * Sort order per course:
 *   - Completed activities first, by ascending totalDurationSec
 *   - Then running/abandoned, by descending punchCount then ascending elapsedSec
 *   - Ties broken by startedAt (older first) so stable across polls.
 */
final class EventRankingsController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route(
        path: '/api/events/{id}/rankings',
        name: 'api_event_rankings',
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

        /** @var Connection $conn */
        $conn = $this->em->getConnection();

        // One SQL pass to fetch every ranking datum we need. We COUNT
        // punches only for controls that count toward completion (i.e.
        // real numbered controls — start/finish carry code '0' or role
        // markers that shouldn't inflate the punchCount).
        $rows = $conn->executeQuery(
            <<<'SQL'
            SELECT
                c.id AS course_id,
                c.name AS course_name,
                (
                    SELECT COUNT(*)
                    FROM course_controls cc
                    INNER JOIN controls ct ON ct.id = cc.control_id
                    WHERE cc.course_id = c.id
                      AND (ct.type IS NULL OR ct.type = 'control')
                ) AS controls_count,
                a.id AS activity_id,
                a.pseudo AS pseudo,
                a.status AS status,
                a.started_at AS started_at,
                a.finished_at AS finished_at,
                a.total_duration_sec AS total_duration_sec,
                TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS user_name,
                (
                    -- Count DISTINCT controls actually part of this
                    -- activity's course, so we ignore:
                    --   * re-punches on the same control
                    --   * mispunches on controls not in this course
                    --   * start/finish rows (excluded via ct2.type check)
                    SELECT COUNT(DISTINCT p.control_id)
                    FROM punches p
                    INNER JOIN controls ct2 ON ct2.id = p.control_id
                    INNER JOIN course_controls cc2
                        ON cc2.control_id = p.control_id
                        AND cc2.course_id = a.course_id
                    WHERE p.activity_id = a.id
                      AND (ct2.type IS NULL OR ct2.type = 'control')
                ) AS punch_count
            FROM courses c
            LEFT JOIN activities a ON a.course_id = c.id
            LEFT JOIN users u ON u.id = a.user_id
            WHERE c.event_id = :eventId
            ORDER BY c.name ASC, a.started_at ASC NULLS LAST
            SQL,
            ['eventId' => $id],
        )->fetchAllAssociative();

        /**
         * @var array<string, array{
         *   iri: string, name: string, controlsCount: int,
         *   entries: list<array<string, mixed>>
         * }>
         */
        $courses = [];
        foreach ($rows as $r) {
            $courseId = $r['course_id'];
            if (!isset($courses[$courseId])) {
                $courses[$courseId] = [
                    'iri' => sprintf('/api/courses/%s', $courseId),
                    'name' => $r['course_name'],
                    'controlsCount' => (int) $r['controls_count'],
                    'entries' => [],
                ];
            }
            if ($r['activity_id'] === null) {
                continue; // Course exists but no activity yet.
            }
            $status = ActivityStatus::tryFrom((string) $r['status']);
            $startedAt = $r['started_at'] !== null ? new \DateTimeImmutable((string) $r['started_at']) : null;
            $finishedAt = $r['finished_at'] !== null ? new \DateTimeImmutable((string) $r['finished_at']) : null;
            $elapsed = null;
            if ($startedAt !== null && $finishedAt !== null) {
                $elapsed = $finishedAt->getTimestamp() - $startedAt->getTimestamp();
            } elseif ($startedAt !== null) {
                $elapsed = time() - $startedAt->getTimestamp();
            }
            $courses[$courseId]['entries'][] = [
                'activityId' => $r['activity_id'],
                'pseudo' => $r['pseudo'] !== null && trim((string) $r['pseudo']) !== ''
                    ? $r['pseudo']
                    : (!empty($r['user_name']) ? $r['user_name'] : 'Anonyme'),
                'punchCount' => (int) $r['punch_count'],
                'elapsedSec' => $elapsed,
                'status' => $status?->value ?? 'running',
                'startedAt' => $startedAt?->format(\DateTimeInterface::ATOM),
                'finishedAt' => $finishedAt?->format(\DateTimeInterface::ATOM),
                'totalDurationSec' => $r['total_duration_sec'] !== null ? (int) $r['total_duration_sec'] : null,
            ];
        }

        // Sort entries per course:
        //   1. completed (by total_duration_sec asc)
        //   2. running (by punch_count desc, elapsed asc)
        //   3. abandoned last
        foreach ($courses as &$course) {
            usort($course['entries'], function (array $a, array $b): int {
                $orderA = match ($a['status']) {
                    'completed' => 0,
                    'running' => 1,
                    default => 2,
                };
                $orderB = match ($b['status']) {
                    'completed' => 0,
                    'running' => 1,
                    default => 2,
                };
                if ($orderA !== $orderB) {
                    return $orderA <=> $orderB;
                }
                if ($orderA === 0) {
                    return ($a['totalDurationSec'] ?? PHP_INT_MAX) <=> ($b['totalDurationSec'] ?? PHP_INT_MAX);
                }
                if ($a['punchCount'] !== $b['punchCount']) {
                    return $b['punchCount'] <=> $a['punchCount']; // more punches first
                }
                return ($a['elapsedSec'] ?? PHP_INT_MAX) <=> ($b['elapsedSec'] ?? PHP_INT_MAX);
            });
        }
        unset($course);

        return new JsonResponse([
            'eventId' => $id,
            'eventName' => $event->getName(),
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'courses' => array_values($courses),
        ]);
    }
}
