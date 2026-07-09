<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\LocationRequestRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Audit trail — returns the last 50 locate-requests fired on an
 * activity. Visible to:
 *   - the runner themselves (their transparency clause)
 *   - any manager of the event owning the activity's course
 *
 * Ordered most-recent first. Small enough to render as-is on the
 * runner's settings screen.
 */
final class ActivityLocationRequestsController
{
    public function __construct(
        private readonly Security $security,
        private readonly ActivityRepository $activities,
        private readonly LocationRequestRepository $requests,
    ) {
    }

    #[Route(
        path: '/api/activities/{id}/location-requests',
        name: 'api_activity_location_requests',
        methods: ['GET'],
        requirements: ['id' => '[0-9a-f-]{36}'],
        format: 'json',
    )]
    #[IsGranted('ROLE_USER')]
    public function __invoke(string $id): JsonResponse
    {
        $activity = $this->activities->find($id);
        if ($activity === null) {
            throw new NotFoundHttpException('Activity not found.');
        }
        $viewer = $this->security->getUser();
        if (!$viewer instanceof User) {
            throw new AccessDeniedHttpException('Missing authenticated user.');
        }
        $isOwner = $activity->getUser()->getId()->equals($viewer->getId());
        $isManager = $this->security->isGranted('manage', $activity->getCourse()->getEvent());
        if (!$isOwner && !$isManager) {
            throw new AccessDeniedHttpException(
                'Only the runner or an event manager can view these requests.',
            );
        }

        $rows = $this->requests->findRecentForActivity($activity, 50);
        return new JsonResponse([
            'activityId' => $activity->getId()->toRfc4122(),
            'requests' => array_map(function ($r) {
                $rb = $r->getRequestedBy();
                return [
                    'id' => $r->getId()->toRfc4122(),
                    'reason' => $r->getReason()->value,
                    'freeText' => $r->getFreeText(),
                    'requestedAt' => $r->getRequestedAt()->format(\DateTimeInterface::ATOM),
                    'answeredAt' => $r->getAnsweredAt()?->format(\DateTimeInterface::ATOM),
                    'requestedBy' => [
                        'id' => $rb->getId()->toRfc4122(),
                        'name' => trim(($rb->getFirstName() ?? '') . ' ' . ($rb->getLastName() ?? '')),
                    ],
                ];
            }, $rows),
        ]);
    }
}
