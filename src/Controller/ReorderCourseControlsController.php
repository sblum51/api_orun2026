<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Course;
use App\Entity\CourseControl;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Atomic re-ordering of a course's CourseControls.
 *
 * The naive client-side approach (PATCH two rows in parallel to swap their
 * positions) trips two failure modes at once:
 *   - Postgres' unique (course_id, position) constraint rejects either
 *     PATCH because the target position is already occupied by the other
 *     row mid-transaction.
 *   - Two concurrent transactions racing for opposing locks deadlock.
 *
 * This endpoint receives the full desired order in a single request and
 * rewrites positions in two SQL passes inside one transaction:
 *   1. Multiply every row's position by -1 to free the positive range.
 *   2. Assign 1..N along the requested order.
 *
 * Both passes touch the same rows in the same order, so no inter-transaction
 * deadlock; and the (course_id, position) tuples are always disjoint.
 */
final class ReorderCourseControlsController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    #[Route(
        path: '/api/courses/{id}/course_controls/reorder',
        name: 'api_course_controls_reorder',
        methods: ['POST'],
        requirements: ['id' => '[0-9a-f-]{36}'],
        format: 'json',
    )]
    #[IsGranted('ROLE_USER')]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $course = $this->em->find(Course::class, $id);
        if (null === $course) {
            throw new NotFoundHttpException('Course not found.');
        }
        if (!$this->security->isGranted('manage', $course->getEvent())) {
            throw new AccessDeniedHttpException('You can only reorder controls of a course you manage.');
        }

        $payload = json_decode((string) $request->getContent(), true);
        $rawIris = $payload['ids'] ?? null;
        if (!\is_array($rawIris) || [] === $rawIris) {
            throw new BadRequestHttpException('Body must be `{ "ids": [<iri1>, <iri2>, ...] }`.');
        }

        // Extract UUIDs from IRIs (`/api/course_controls/<uuid>` → `<uuid>`)
        // and reject anything malformed up front so we don't half-update.
        $orderedIds = [];
        foreach ($rawIris as $iri) {
            if (!\is_string($iri) || 1 !== preg_match('#/api/course_controls/([0-9a-f-]{36})$#', $iri, $m)) {
                throw new BadRequestHttpException('Each id must be a CourseControl IRI.');
            }
            $orderedIds[] = $m[1];
        }

        // Sanity: every id must belong to this course, and the list must
        // cover EVERY CourseControl of the course (no missing, no extras).
        // A partial reorder would leave gaps and break the 1..N invariant
        // the rest of the app assumes.
        $existing = $this->em->getRepository(CourseControl::class)->findBy(['course' => $course]);
        $existingIds = array_map(static fn (CourseControl $cc) => $cc->getId()->toRfc4122(), $existing);
        sort($existingIds);
        $sortedRequested = $orderedIds;
        sort($sortedRequested);
        if ($existingIds !== $sortedRequested) {
            throw new BadRequestHttpException(sprintf(
                'The id list must match the course\'s CourseControls exactly. Got %d, expected %d.',
                \count($orderedIds),
                \count($existingIds),
            ));
        }

        /** @var Connection $conn */
        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            // Pass 1: free the positive range. NULL doesn't trip the unique
            // constraint because position is NOT NULL — use negation instead.
            $conn->executeStatement(
                'UPDATE course_controls SET position = -position WHERE course_id = :cid',
                ['cid' => $id],
            );

            // Pass 2: assign final positions in the requested order.
            // DBAL 4: `Statement::executeStatement()` no longer accepts
            // params in-call — bind explicitly before each execution.
            $stmt = $conn->prepare('UPDATE course_controls SET position = :pos WHERE id = :id');
            foreach ($orderedIds as $i => $ccId) {
                $stmt->bindValue('pos', $i + 1);
                $stmt->bindValue('id', $ccId);
                $stmt->executeStatement();
            }

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        // Clear the in-memory entities so subsequent fetches see fresh
        // positions, not the cached pre-reorder values.
        $this->em->clear(CourseControl::class);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
