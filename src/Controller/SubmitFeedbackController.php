<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Feedback;
use App\Repository\ActivityRepository;
use App\Repository\FeedbackRepository;
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
 * Upsert the runner's feedback on their activity — the mobile hits
 * this after the finish outcome. Called with the same payload for
 * first-time creation or an update: server treats it as an upsert
 * keyed by (activity_id).
 *
 * Auth:
 *   - Only the ACTIVITY OWNER can post; spoofing another runner's
 *     rating would be trivially bad for reputation systems.
 *   - The event's `feedbackEnabled` flag is honoured — if the manager
 *     disabled the module, we return 403 so the client stops trying.
 */
final class SubmitFeedbackController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly ActivityRepository $activities,
        private readonly FeedbackRepository $feedbacks,
    ) {
    }

    #[Route(
        path: '/api/activities/{id}/feedback',
        name: 'api_activity_feedback_submit',
        methods: ['POST', 'PUT'],
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
        if (!$activity->getUser()->getId()->equals($user->getId())) {
            throw new AccessDeniedHttpException('You can only rate your own run.');
        }
        $event = $activity->getCourse()->getEvent();
        if (!$event->isFeedbackEnabled()) {
            throw new AccessDeniedHttpException('Feedback is disabled for this event.');
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!\is_array($payload)) {
            throw new BadRequestHttpException('Body must be a JSON object.');
        }
        $rating = $payload['rating'] ?? null;
        $comment = $payload['comment'] ?? null;
        if (!\is_int($rating) || $rating < 1 || $rating > 5) {
            throw new BadRequestHttpException('rating must be an integer 1-5.');
        }
        if ($comment !== null && (!\is_string($comment) || mb_strlen($comment) > 2000)) {
            throw new BadRequestHttpException('comment must be a string ≤ 2000 chars.');
        }
        $comment = \is_string($comment) ? mb_substr(trim($comment), 0, 2000) : null;
        if ($comment === '') {
            $comment = null;
        }

        $existing = $this->feedbacks->findOneForActivity($activity);
        if ($existing === null) {
            $existing = new Feedback($activity, $rating, $comment);
            $this->em->persist($existing);
        } else {
            $existing->update($rating, $comment);
        }
        $this->em->flush();

        return new JsonResponse([
            'id' => $existing->getId()->toRfc4122(),
            'rating' => $existing->getRating(),
            'comment' => $existing->getComment(),
        ]);
    }
}
