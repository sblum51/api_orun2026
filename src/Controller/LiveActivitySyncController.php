<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Activity;
use App\Entity\Control;
use App\Entity\Course;
use App\Entity\Punch;
use App\Enum\ActivityStatus;
use App\Enum\ControlValidationMethod;
use App\Repository\ActivityRepository;
use App\Repository\ControlRepository;
use App\Repository\CourseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One endpoint the mobile app hits on every punch (and on start/finish)
 * to keep the server-side Activity in sync with the local Run state.
 *
 * Idempotent: keyed by (user, localRunId). The client sends the same
 * `localRunId` on every push for the same local run; the server upserts
 * one Activity per (user, localRunId). Punches are reconciled by
 * (controlIri, punchedAt) — duplicates are silently skipped so retries
 * over a flaky link don't produce phantom pointages.
 *
 * Body:
 *   {
 *     "localRunId": "uuid",
 *     "courseIri": "/api/courses/xxx",
 *     "pseudo": "Simon" | null,
 *     "startedAt": "2026-07-02T10:00:00Z" | null,
 *     "finishedAt": "2026-07-02T11:00:00Z" | null,
 *     "punches": [
 *       { "controlIri": "/api/controls/yyy",
 *         "methodUsed": "ibeacon",
 *         "punchedAt": "2026-07-02T10:12:34Z",
 *         "latitude": null, "longitude": null }
 *     ]
 *   }
 *
 * Response: { activityIri, punchesPersisted, punchesSkipped }.
 * Manager screens can then poll the Activity to render live rankings.
 */
final class LiveActivitySyncController
{
    /** Match tolerance when deduping punches, in seconds. */
    private const PUNCH_DEDUP_WINDOW = 2.0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly ActivityRepository $activities,
        private readonly CourseRepository $courses,
        private readonly ControlRepository $controls,
    ) {
    }

    #[Route(
        path: '/api/activities/live-sync',
        name: 'api_activities_live_sync',
        methods: ['POST'],
        format: 'json',
    )]
    #[IsGranted('ROLE_USER')]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if ($user === null || !method_exists($user, 'getId')) {
            throw new UnauthorizedHttpException('Bearer', 'Missing authenticated user.');
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!\is_array($payload)) {
            throw new BadRequestHttpException('Body must be a JSON object.');
        }

        $localRunId = $payload['localRunId'] ?? null;
        $courseIri = $payload['courseIri'] ?? null;
        if (!\is_string($localRunId) || 1 !== preg_match('/^[0-9a-f-]{36}$/', $localRunId)) {
            throw new BadRequestHttpException('localRunId must be a UUID.');
        }
        if (!\is_string($courseIri) || 1 !== preg_match('#/api/courses/([0-9a-f-]{36})$#', $courseIri, $m)) {
            throw new BadRequestHttpException('courseIri must be a Course IRI.');
        }
        $course = $this->courses->find($m[1]);
        if ($course === null) {
            throw new NotFoundHttpException('Course not found.');
        }

        $startedAt = $this->parseIsoDate($payload['startedAt'] ?? null);
        $finishedAt = $this->parseIsoDate($payload['finishedAt'] ?? null);
        $pseudo = isset($payload['pseudo']) && \is_string($payload['pseudo'])
            ? mb_substr(trim($payload['pseudo']), 0, 100)
            : null;
        if ($pseudo === '') {
            $pseudo = null;
        }

        // Upsert the activity by (user, localRunId).
        $activity = $this->activities->findOneBy([
            'user' => $user,
            'localRunId' => $localRunId,
        ]);
        if ($activity === null) {
            $activity = new Activity($user, $course, $startedAt ?? new \DateTimeImmutable());
            $activity->setLocalRunId($localRunId);
            $this->em->persist($activity);
        }
        // Refresh mutable fields on every sync so the server tracks the
        // latest pseudo / start / finish the client has agreed on.
        $activity->setPseudo($pseudo);
        if ($finishedAt !== null) {
            $activity->setFinishedAt($finishedAt);
            $activity->setStatus(ActivityStatus::Completed);
            if ($startedAt !== null) {
                $activity->setTotalDurationSec($finishedAt->getTimestamp() - $startedAt->getTimestamp());
            }
        } else {
            $activity->setStatus(ActivityStatus::Running);
        }

        $persisted = 0;
        $skipped = 0;
        $rawPunches = $payload['punches'] ?? [];
        if (\is_array($rawPunches)) {
            $existing = $activity->getPunches()->toArray();
            foreach ($rawPunches as $p) {
                if (!\is_array($p)) {
                    ++$skipped;
                    continue;
                }
                $controlIri = $p['controlIri'] ?? null;
                $methodStr = $p['methodUsed'] ?? null;
                $punchedAt = $this->parseIsoDate($p['punchedAt'] ?? null);
                if (!\is_string($controlIri) || 1 !== preg_match('#/api/controls/([0-9a-f-]{36})$#', $controlIri, $cm)) {
                    ++$skipped;
                    continue;
                }
                $method = ControlValidationMethod::tryFrom((string) $methodStr);
                if ($method === null || $punchedAt === null) {
                    ++$skipped;
                    continue;
                }
                $control = $this->controls->find($cm[1]);
                if ($control === null) {
                    ++$skipped;
                    continue;
                }
                if ($this->isDuplicate($existing, $control, $punchedAt)) {
                    ++$skipped;
                    continue;
                }
                $punch = new Punch($activity, $control, $punchedAt, $method);
                $lat = $p['latitude'] ?? null;
                $lng = $p['longitude'] ?? null;
                if (\is_numeric($lat)) {
                    $punch->setLatitude((float) $lat);
                }
                if (\is_numeric($lng)) {
                    $punch->setLongitude((float) $lng);
                }
                $this->em->persist($punch);
                $existing[] = $punch;
                ++$persisted;
            }
        }

        $this->em->flush();

        return new JsonResponse([
            'activityIri' => sprintf('/api/activities/%s', $activity->getId()->toRfc4122()),
            'punchesPersisted' => $persisted,
            'punchesSkipped' => $skipped,
        ]);
    }

    /**
     * @param list<Punch> $existing
     */
    private function isDuplicate(array $existing, Control $control, \DateTimeImmutable $when): bool
    {
        $ts = (float) $when->format('U.u');
        foreach ($existing as $p) {
            if ($p->getControl()->getId()->toRfc4122() !== $control->getId()->toRfc4122()) {
                continue;
            }
            $diff = abs((float) $p->getPunchedAt()->format('U.u') - $ts);
            if ($diff <= self::PUNCH_DEDUP_WINDOW) {
                return true;
            }
        }
        return false;
    }

    private function parseIsoDate(mixed $raw): ?\DateTimeImmutable
    {
        if (!\is_string($raw) || $raw === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
