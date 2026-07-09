<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Activity;
use App\Repository\ActivityRepository;
use App\Repository\LocationRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Endpoint the mobile app hits every 30 s during an active run to push
 * the runner's current position (nominal live-tracking flow). Same
 * endpoint serves the emergency one-shot POST triggered by a silent
 * push — the mobile app doesn't distinguish, the server just writes
 * the latest reading.
 *
 * Auth : only the OWNER of the activity can post their own location.
 * Prevents anyone from spoofing another runner's position.
 */
final class ActivityLocationController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly ActivityRepository $activities,
        private readonly LocationRequestRepository $requests,
    ) {
    }

    #[Route(
        path: '/api/activities/{id}/location',
        name: 'api_activity_location',
        methods: ['POST'],
        requirements: ['id' => '[0-9a-f-]{36}'],
        format: 'json',
    )]
    #[IsGranted('ROLE_USER')]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $activity = $this->activities->find($id);
        if ($activity === null) {
            throw new NotFoundHttpException('Activity not found.');
        }
        $user = $this->security->getUser();
        if ($user === null || !method_exists($user, 'getId')) {
            throw new AccessDeniedHttpException('Missing authenticated user.');
        }
        // Only the owner can update their own position — hard rule to
        // prevent any client from spoofing another runner's location.
        if (!$activity->getUser()->getId()->equals($user->getId())) {
            throw new AccessDeniedHttpException('You can only publish your own location.');
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!\is_array($payload)) {
            throw new BadRequestHttpException('Body must be a JSON object.');
        }
        $lat = $payload['lat'] ?? null;
        $lng = $payload['lng'] ?? null;
        $atRaw = $payload['at'] ?? null;
        if (!\is_numeric($lat) || !\is_numeric($lng)) {
            throw new BadRequestHttpException('lat and lng must be numbers.');
        }
        $latF = (float) $lat;
        $lngF = (float) $lng;
        if ($latF < -90 || $latF > 90 || $lngF < -180 || $lngF > 180) {
            throw new BadRequestHttpException('lat/lng out of bounds.');
        }
        $at = $this->parseIsoDate($atRaw) ?? new \DateTimeImmutable();

        $activity->setLastLocation($latF, $lngF, $at);

        // If a locate request is currently pending, latch its answer
        // timestamp so the manager sees "answered in Xs" in the audit
        // trail. Only the first fresh position after the request
        // counts; subsequent nominal 30 s pings don't re-mark it.
        $latestRequest = $this->requests->findLatestForActivity($activity);
        if ($latestRequest !== null && $latestRequest->getAnsweredAt() === null) {
            $latestRequest->markAnswered();
        }

        $this->em->flush();

        return new JsonResponse(['ok' => true, 'at' => $at->format(\DateTimeInterface::ATOM)]);
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
