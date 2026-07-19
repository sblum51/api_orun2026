<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\LegacyImporter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Materialises a legacy event into the new backend, courses + maps +
 * controls included. Idempotent by `Event.legacy_slug`.
 */
final class LegacyImportController
{
    public function __construct(
        private readonly LegacyImporter $importer,
        private readonly Security $security,
    ) {
    }

    #[Route(
        path: '/api/legacy/events/{slug}/import',
        name: 'api_legacy_events_import',
        methods: ['POST'],
        format: 'json',
    )]
    #[IsGranted('ROLE_USER')]
    public function __invoke(string $slug): JsonResponse
    {
        if ($slug === '' || !preg_match('/^[a-z0-9-]{2,200}$/i', $slug)) {
            throw new BadRequestHttpException('Invalid legacy slug.');
        }
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Legacy import requires an authenticated user.');
        }

        $result = $this->importer->importBySlug($slug, $user);
        $event = $result['event'];

        return new JsonResponse([
            'event' => [
                'id' => $event->getId()->toRfc4122(),
                'name' => $event->getName(),
                'slug' => $event->getSlug(),
                'legacySlug' => $event->getLegacySlug(),
            ],
            'coursesCreated' => $result['coursesCreated'],
            'coursesUpdated' => $result['coursesUpdated'],
            'controlsCreated' => $result['controlsCreated'],
            'mapsImported' => $result['mapsImported'],
        ]);
    }
}
