<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\LocationRequest;
use App\Entity\User;
use App\Enum\ActivityStatus;
use App\Enum\LocationRequestReason;
use App\Repository\ActivityRepository;
use App\Repository\LocationRequestRepository;
use App\Repository\UserDeviceRepository;
use App\Service\PushNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Emergency locate — fires a silent push to every registered device of
 * the activity's runner, asking their app to POST its current
 * position back to `{activityIri}/location`. Also creates a
 * LocationRequest audit row visible to both the runner and the
 * organiser.
 *
 * Auth : caller must `manage` the parent event of the activity's
 * course. Additionally rate-limited to 1 request per activity per
 * 5 minutes so an organiser can't spam a runner's battery.
 */
final class RequestActivityLocationController
{
    /** Minimum gap between two locate requests on the same activity. */
    private const RATE_LIMIT_SECONDS = 300;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly ActivityRepository $activities,
        private readonly UserDeviceRepository $devices,
        private readonly LocationRequestRepository $requests,
        private readonly PushNotifier $push,
    ) {
    }

    #[Route(
        path: '/api/activities/{id}/request-location',
        name: 'api_activity_request_location',
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
        $event = $activity->getCourse()->getEvent();
        if (!$this->security->isGranted('manage', $event)) {
            throw new AccessDeniedHttpException(
                'You must manage the event of this activity to request a location.',
            );
        }
        if ($activity->getStatus() !== ActivityStatus::Running) {
            throw new BadRequestHttpException(
                'Location requests are only allowed on running activities.',
            );
        }
        $caller = $this->security->getUser();
        if (!$caller instanceof User) {
            throw new AccessDeniedHttpException('Missing authenticated user.');
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!\is_array($payload)) {
            throw new BadRequestHttpException('Body must be a JSON object.');
        }
        $reason = LocationRequestReason::tryFrom((string) ($payload['reason'] ?? ''));
        $freeText = $payload['freeText'] ?? null;
        if ($reason === null) {
            throw new BadRequestHttpException('reason must be one of lost, injured, check_in, other.');
        }
        if ($reason->requiresFreeText() && (!\is_string($freeText) || trim($freeText) === '')) {
            throw new BadRequestHttpException('freeText required when reason is "other".');
        }
        $freeText = \is_string($freeText) ? mb_substr(trim($freeText), 0, 255) : null;

        // Rate-limit — even a well-intentioned organiser shouldn't hammer
        // a runner in distress; the app has 30 s to answer before a
        // second push is useful.
        $latest = $this->requests->findLatestForActivity($activity);
        if ($latest !== null) {
            $elapsed = time() - $latest->getRequestedAt()->getTimestamp();
            if ($elapsed < self::RATE_LIMIT_SECONDS) {
                throw new TooManyRequestsHttpException(
                    self::RATE_LIMIT_SECONDS - $elapsed,
                    sprintf(
                        'Attends %d s avant de renvoyer une demande à ce coureur.',
                        self::RATE_LIMIT_SECONDS - $elapsed,
                    ),
                );
            }
        }

        // Create the audit row BEFORE sending the pushes so a crash
        // between them still leaves a trace of the organiser's intent.
        $auditRow = new LocationRequest($activity, $caller, $reason, $freeText);
        $this->em->persist($auditRow);
        $this->em->flush();

        $runner = $activity->getUser();
        $devices = $this->devices->findByUser($runner);
        $sentTo = 0;
        foreach ($devices as $device) {
            $ok = $this->push->send($device, [
                'type' => 'emergency-locate',
                'activityId' => $activity->getId()->toRfc4122(),
                'activityIri' => sprintf('/api/activities/%s', $activity->getId()->toRfc4122()),
                'requestId' => $auditRow->getId()->toRfc4122(),
                'reason' => $reason->value,
            ]);
            if ($ok) {
                ++$sentTo;
            }
        }

        return new JsonResponse([
            'requestId' => $auditRow->getId()->toRfc4122(),
            'sentTo' => $sentTo,
            'registeredDevices' => \count($devices),
        ]);
    }
}
