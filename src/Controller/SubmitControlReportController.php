<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ControlReport;
use App\Enum\ControlReportReason;
use App\Repository\ActivityRepository;
use App\Repository\ControlRepository;
use App\Service\MapStorage;
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
 * Runner submits a control report ("poste absent" / "poste abîmé").
 *
 * Accepted as **multipart/form-data** so the runner can attach a
 * picture:
 *   - `controlIri` (string) — required
 *   - `reason`     (missing | damaged) — required
 *   - `comment`    (string, ≤ 2000) — optional
 *   - `photo`      (file, JPEG/PNG/WebP, ≤ 4 MiB) — optional
 *
 * Auth: activity owner only + `event.controlReportsEnabled === true`.
 * Same guard as feedback — the manager can flip the module off from
 * their side to freeze new signalements.
 */
final class SubmitControlReportController
{
    private const MAX_PHOTO_BYTES = 4 * 1024 * 1024;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly ActivityRepository $activities,
        private readonly ControlRepository $controls,
        private readonly MapStorage $storage,
    ) {
    }

    #[Route(
        path: '/api/activities/{id}/control-reports',
        name: 'api_activity_control_report_submit',
        methods: ['POST'],
        requirements: ['id' => '[0-9a-f-]{36}'],
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
            throw new AccessDeniedHttpException('You can only report on your own run.');
        }
        $event = $activity->getCourse()->getEvent();
        if (!$event->isControlReportsEnabled()) {
            throw new AccessDeniedHttpException('Reports are disabled for this event.');
        }

        $controlIri = $request->request->get('controlIri');
        if (!\is_string($controlIri) || 1 !== preg_match('#/api/controls/([0-9a-f-]{36})$#', $controlIri, $m)) {
            throw new BadRequestHttpException('controlIri must be a Control IRI.');
        }
        $control = $this->controls->find($m[1]);
        if ($control === null) {
            throw new NotFoundHttpException('Control not found.');
        }
        if ($control->getEvent()->getId()->toRfc4122() !== $event->getId()->toRfc4122()) {
            throw new BadRequestHttpException('Control does not belong to this activity\'s event.');
        }

        $reason = ControlReportReason::tryFrom((string) $request->request->get('reason', ''));
        if ($reason === null) {
            throw new BadRequestHttpException('reason must be one of: missing, damaged.');
        }
        $comment = $request->request->get('comment');
        if ($comment !== null && (!\is_string($comment) || mb_strlen($comment) > 2000)) {
            throw new BadRequestHttpException('comment must be a string ≤ 2000 chars.');
        }
        $comment = \is_string($comment) ? mb_substr(trim($comment), 0, 2000) : null;
        if ($comment === '') {
            $comment = null;
        }

        $report = new ControlReport($activity, $control, $reason, $comment);

        // Optional photo — same validate/hash/store pipeline as the
        // event cover upload, in a `control-reports/` subfolder for
        // easy cleanup.
        $photo = $request->files->get('photo');
        if ($photo !== null) {
            if ($photo->getSize() > self::MAX_PHOTO_BYTES) {
                throw new BadRequestHttpException(sprintf(
                    'Photo too large (%d bytes). Max %d.',
                    (int) $photo->getSize(),
                    self::MAX_PHOTO_BYTES,
                ));
            }
            ['mime' => $mime, 'ext' => $ext] = $this->validateImage($photo->getPathname());
            $hash = sha1_file($photo->getPathname());
            if ($hash === false) {
                throw new BadRequestHttpException('Cannot hash uploaded photo.');
            }
            $key = sprintf(
                'control-reports/%s/%s.%s',
                $activity->getId()->toRfc4122(),
                $hash,
                $ext,
            );
            $report->setPhotoUrl($this->storage->storeFile($key, $photo->getPathname(), $mime));
        }

        $this->em->persist($report);
        $this->em->flush();

        return new JsonResponse([
            'id' => $report->getId()->toRfc4122(),
            'controlIri' => $controlIri,
            'reason' => $report->getReason()->value,
            'status' => $report->getStatus()->value,
            'comment' => $report->getComment(),
            'photoUrl' => $report->getPhotoUrl(),
            'createdAt' => $report->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], 201);
    }

    /**
     * @return array{mime: string, ext: string}
     */
    private function validateImage(string $path): array
    {
        $info = @getimagesize($path);
        if ($info === false) {
            throw new BadRequestHttpException('Uploaded photo is not a valid image.');
        }
        return match ($info[2]) {
            \IMAGETYPE_JPEG => ['mime' => 'image/jpeg', 'ext' => 'jpg'],
            \IMAGETYPE_PNG => ['mime' => 'image/png', 'ext' => 'png'],
            \IMAGETYPE_WEBP => ['mime' => 'image/webp', 'ext' => 'webp'],
            default => throw new BadRequestHttpException('Photo must be JPEG, PNG or WebP.'),
        };
    }
}
