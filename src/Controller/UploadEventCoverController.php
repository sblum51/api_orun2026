<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Event;
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
 * Accepts a multipart upload of the event's cover photo. The manager calls
 * this after picking an image; the bytes go through {@see MapStorage} which
 * already knows how to write to the configured filesystem (local or S3),
 * then we stamp the resulting URL onto the event.
 *
 * Image is taken as-is — we don't downscale on the server. The manager is
 * expected to pre-shrink large pictures via a `<canvas>` before upload
 * (same pattern as the KMZ import flow). Hard cap at 4 MiB so a forgotten
 * downscale doesn't choke the worker.
 */
final class UploadEventCoverController
{
    private const MAX_BYTES = 4 * 1024 * 1024;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MapStorage $storage,
        private readonly Security $security,
    ) {
    }

    #[Route(
        path: '/api/events/{id}/cover-image',
        name: 'api_events_cover_upload',
        methods: ['POST'],
        requirements: ['id' => '[0-9a-f-]{36}'],
        format: 'json',
    )]
    #[IsGranted('ROLE_USER')]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $event = $this->em->find(Event::class, $id);
        if (null === $event) {
            throw new NotFoundHttpException('Event not found.');
        }
        if (!$this->security->isGranted('manage', $event)) {
            throw new AccessDeniedHttpException('You can only set a cover on an event you manage.');
        }

        $upload = $request->files->get('image');
        if (null === $upload) {
            throw new BadRequestHttpException('Missing "image" upload.');
        }
        if ($upload->getSize() > self::MAX_BYTES) {
            throw new BadRequestHttpException(sprintf(
                'Image too large (%d bytes). Downscale to under %d bytes before upload.',
                (int) $upload->getSize(),
                self::MAX_BYTES,
            ));
        }

        ['mime' => $mime, 'ext' => $ext] = $this->validateImage($upload->getPathname());

        // Content-addressable key so re-uploading the same picture is a no-op
        // on storage, and so cache headers can be aggressive — the URL changes
        // whenever the bytes change.
        $hash = sha1_file($upload->getPathname());
        if (false === $hash) {
            throw new BadRequestHttpException('Cannot hash uploaded image.');
        }
        $key = sprintf('events/%s/cover-%s.%s', $event->getId()->toRfc4122(), $hash, $ext);
        $url = $this->storage->storeFile($key, $upload->getPathname(), $mime);

        $event->setCoverImageUrl($url);
        $this->em->flush();

        return new JsonResponse([
            '@id' => '/api/events/'.$event->getId()->toRfc4122(),
            'coverImageUrl' => $url,
        ]);
    }

    /**
     * @return array{mime: string, ext: string}
     */
    private function validateImage(string $path): array
    {
        $info = @getimagesize($path);
        if (false === $info) {
            throw new BadRequestHttpException('Uploaded file is not a valid image.');
        }
        return match ($info[2]) {
            \IMAGETYPE_JPEG => ['mime' => 'image/jpeg', 'ext' => 'jpg'],
            \IMAGETYPE_PNG => ['mime' => 'image/png', 'ext' => 'png'],
            \IMAGETYPE_WEBP => ['mime' => 'image/webp', 'ext' => 'webp'],
            default => throw new BadRequestHttpException('Unsupported image format. Use JPEG, PNG or WebP.'),
        };
    }
}
