<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\EventRepository;
use App\Service\LegacyOrunClient;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Proxy over the legacy Orun `GET /events` search. Two reasons for
 * going through our backend instead of hitting api.orun.app directly
 * from the manager SPA:
 *
 *   1. CORS — the legacy backend allows only known origins.
 *   2. Import status — for each result we annotate whether the event
 *      has ALREADY been imported into this new backend (via the
 *      `legacy_slug` column), so the UI can render a disabled
 *      "Déjà importé" chip.
 *
 * Query params flow through untouched — the manager can pass any of
 * the legacy filters (`order[createdAt]=desc`, `enabled=true`,
 * `apps=orun_app`, `settings.availableInListings=true`, etc.).
 */
final class LegacyBrowseController
{
    public function __construct(
        private readonly LegacyOrunClient $legacy,
        private readonly EventRepository $events,
        private readonly Security $security,
    ) {
    }

    #[Route(
        path: '/api/legacy/events',
        name: 'api_legacy_events_browse',
        methods: ['GET'],
        format: 'json',
    )]
    #[IsGranted('ROLE_USER')]
    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->security->isGranted('ROLE_USER')) {
            throw new AccessDeniedHttpException('Legacy import requires a logged-in user.');
        }
        // Whitelist the filters we forward — dodges an attacker
        // shipping arbitrary internal fields via the SPA.
        $allowedKeys = [
            'q', 'name', 'search',
            'order[createdAt]', 'order[updatedAt]', 'order[startDate]',
            'enabled', 'apps', 'settings.availableInListings',
            'page', 'itemsPerPage', 'organization',
        ];
        $query = [];
        foreach ($allowedKeys as $k) {
            $v = $request->query->get($k);
            if ($v !== null && $v !== '') {
                $query[$k] = $v;
            }
        }
        $body = $this->legacy->browseEvents($query);

        $items = $body['hydra:member'] ?? $body['member'] ?? [];
        $out = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $slug = $item['slug'] ?? null;
            $imported = null;
            if (\is_string($slug) && $slug !== '') {
                $found = $this->events->findOneBy(['legacySlug' => $slug]);
                $imported = $found !== null ? [
                    'id' => $found->getId()->toRfc4122(),
                    'name' => $found->getName(),
                    'slug' => $found->getSlug(),
                ] : null;
            }
            $out[] = [
                'legacySlug' => $slug,
                'name' => $item['name'] ?? null,
                'description' => $item['description'] ?? null,
                'location' => $item['location'] ?? null,
                'startDate' => $item['startDate'] ?? null,
                'endDate' => $item['endDate'] ?? null,
                'coverImageUrl' => $item['coverImageUrl']
                    ?? $item['illustrationUrl']
                    ?? null,
                'coursesCount' => \is_array($item['courses'] ?? null)
                    ? \count($item['courses'])
                    : ($item['coursesCount'] ?? null),
                'imported' => $imported,
            ];
        }

        return new JsonResponse([
            'total' => $body['hydra:totalItems'] ?? $body['totalItems'] ?? \count($out),
            'items' => $out,
        ]);
    }
}
