<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\EventRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Human-readable event lookup: `GET /api/events/by-slug/{slug}`.
 *
 * Used by the `orun.app` web landing page and by the mobile deep-link
 * handler when a scanned QR carries the URL
 * `https://orun.app/events/{slug}#qrcode_{code}`. The mobile app
 * resolves the slug to a UUID here, then hits the usual
 * `/api/events/{id}` endpoints for detail data.
 *
 * Serialisation reuses the `event:public` group — same shape the
 * mobile app already consumes from `/api/events`.
 */
final class GetEventBySlugController
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly Security $security,
        private readonly NormalizerInterface $normalizer,
    ) {
    }

    #[Route(
        path: '/api/events/by-slug/{slug}',
        name: 'api_event_by_slug',
        methods: ['GET'],
        requirements: ['slug' => '[a-z0-9-]+'],
        format: 'json',
    )]
    public function __invoke(string $slug, Request $request): JsonResponse
    {
        $event = $this->events->findOneBy(['slug' => $slug]);
        if ($event === null) {
            throw new NotFoundHttpException('Event not found.');
        }
        if (!$this->security->isGranted('view', $event)) {
            throw new AccessDeniedHttpException('Cannot view this event.');
        }
        $data = $this->normalizer->normalize($event, 'jsonld', [
            'groups' => ['event:public'],
        ]);
        return new JsonResponse($data);
    }
}
