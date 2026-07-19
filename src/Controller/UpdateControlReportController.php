<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\ControlReportStatus;
use App\Repository\ControlReportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Manager transitions a report: pending → acknowledged / resolved /
 * dismissed. Auth requires `manage` on the parent event.
 */
final class UpdateControlReportController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly ControlReportRepository $reports,
    ) {
    }

    #[Route(
        path: '/api/control-reports/{id}',
        name: 'api_control_report_update',
        methods: ['PATCH'],
        requirements: ['id' => '[0-9a-f-]{36}'],
        format: 'json',
    )]
    #[IsGranted('ROLE_USER')]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $report = $this->reports->find($id);
        if ($report === null) {
            throw new NotFoundHttpException('Report not found.');
        }
        $event = $report->getActivity()->getCourse()->getEvent();
        if (!$this->security->isGranted('manage', $event)) {
            throw new AccessDeniedHttpException(
                'You must manage this event to update reports.',
            );
        }
        $actor = $this->security->getUser();
        if ($actor === null || !method_exists($actor, 'getId')) {
            throw new UnauthorizedHttpException('Bearer', 'Missing authenticated user.');
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!\is_array($payload)) {
            throw new BadRequestHttpException('Body must be a JSON object.');
        }
        $status = ControlReportStatus::tryFrom((string) ($payload['status'] ?? ''));
        if ($status === null) {
            throw new BadRequestHttpException(
                'status must be one of: pending, acknowledged, resolved, dismissed.',
            );
        }
        $report->transition($status, $actor);
        $this->em->flush();

        return new JsonResponse([
            'id' => $report->getId()->toRfc4122(),
            'status' => $report->getStatus()->value,
            'acknowledgedAt' => $report->getAcknowledgedAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }
}
