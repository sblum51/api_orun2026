<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Course;
use App\Entity\Map;
use App\Service\MapStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Accepts a (browser-side, already-downscaled) GroundOverlay image plus its
 * LatLonBox and a target course IRI, validates the bytes, stores them via
 * {@see MapStorage} and creates a {@see Map} row.
 *
 * Used by the manager's client-side KMZ import flow — the browser parses the
 * archive, downscales the image via canvas to a Mapbox-friendly size and
 * POSTs only the final bytes. The server never has to deal with a 15 MiB
 * KMZ archive or rasterize anything itself.
 */
final class CreateMapController
{
    private const MAX_BYTES = 4 * 1024 * 1024;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MapStorage $mapStorage,
        private readonly Security $security,
    ) {
    }

    #[Route(
        path: '/api/maps',
        name: 'api_maps_create',
        methods: ['POST'],
        format: 'json',
    )]
    #[IsGranted('ROLE_USER')]
    public function __invoke(Request $request): JsonResponse
    {
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

        $courseIri = (string) $request->request->get('course', '');
        if (!preg_match('#^/api/courses/([0-9a-f-]{36})$#', $courseIri, $matches)) {
            throw new BadRequestHttpException('Missing or invalid "course" IRI.');
        }
        $course = $this->em->find(Course::class, $matches[1]);
        if (null === $course) {
            throw new BadRequestHttpException('Course not found.');
        }
        if (!$this->security->isGranted('manage', $course->getEvent())) {
            throw new BadRequestHttpException('You can only attach a map to a course you manage.');
        }

        $bounds = $this->parseBounds((string) $request->request->get('bounds', ''));

        ['mime' => $mime, 'ext' => $ext] = $this->validateImage($upload->getPathname());
        $hash = sha1_file($upload->getPathname());
        if (false === $hash) {
            throw new BadRequestHttpException('Cannot hash uploaded image.');
        }
        $key = sprintf('events/%s/%s.%s', $course->getEvent()->getId()->toRfc4122(), $hash, $ext);
        $url = $this->mapStorage->storeFile($key, $upload->getPathname(), $mime);

        $displayName = mb_substr(trim((string) $request->request->get('name', '')) ?: 'Carte', 0, 200);
        $map = new Map($course, $displayName, $url);
        $map->setBounds($bounds);
        $this->em->persist($map);
        $this->em->flush();

        return new JsonResponse(
            [
                '@context' => '/api/contexts/Map',
                '@id' => '/api/maps/'.$map->getId()->toRfc4122(),
                '@type' => 'Map',
                'id' => $map->getId()->toRfc4122(),
                'name' => $map->getName(),
                'imageUrl' => $map->getImageUrl(),
                'bounds' => $map->getBounds(),
                'course' => '/api/courses/'.$course->getId()->toRfc4122(),
            ],
            Response::HTTP_CREATED,
        );
    }

    /**
     * The payload accepts either:
     *  - axis-aligned bbox: north/south/east/west + optional rotation, OR
     *  - 4-corner quadrilateral: corners = [[lng,lat], [lng,lat], [lng,lat], [lng,lat]]
     *    in Mapbox order (TL, TR, BR, BL) — used for gx:LatLonQuad sources
     *    where the image was already rotated/skewed by the OCAD export.
     *
     * @return array<string, mixed>
     */
    private function parseBounds(string $raw): array
    {
        if ('' === $raw) {
            throw new BadRequestHttpException('Missing "bounds" JSON.');
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new BadRequestHttpException('Invalid "bounds" JSON: '.$e->getMessage());
        }
        if (!is_array($decoded)) {
            throw new BadRequestHttpException('"bounds" must be a JSON object.');
        }
        $out = [];

        if (isset($decoded['corners']) && is_array($decoded['corners'])) {
            if (4 !== \count($decoded['corners'])) {
                throw new BadRequestHttpException('"bounds.corners" must contain exactly 4 points.');
            }
            $corners = [];
            foreach ($decoded['corners'] as $idx => $pt) {
                if (!is_array($pt) || 2 !== \count($pt) || !is_numeric($pt[0]) || !is_numeric($pt[1])) {
                    throw new BadRequestHttpException(sprintf('"bounds.corners[%d]" must be [lng, lat].', $idx));
                }
                $corners[] = [(float) $pt[0], (float) $pt[1]];
            }
            $out['corners'] = $corners;
        } elseif (isset($decoded['north'], $decoded['south'], $decoded['east'], $decoded['west'])) {
            foreach (['north', 'south', 'east', 'west'] as $k) {
                if (!is_numeric($decoded[$k])) {
                    throw new BadRequestHttpException(sprintf('"bounds.%s" must be numeric.', $k));
                }
                $out[$k] = (float) $decoded[$k];
            }
            if ($out['north'] <= $out['south']) {
                throw new BadRequestHttpException('"bounds.north" must be greater than "bounds.south".');
            }
            if ($out['east'] === $out['west']) {
                throw new BadRequestHttpException('"bounds.east" must differ from "bounds.west".');
            }
            if (isset($decoded['rotation']) && is_numeric($decoded['rotation'])) {
                $r = (float) $decoded['rotation'];
                if ($r >= -360 && $r <= 360 && 0.0 !== $r) {
                    $out['rotation'] = $r;
                }
            }
        } else {
            throw new BadRequestHttpException('"bounds" must contain either "corners" or north/south/east/west.');
        }

        return $out;
    }

    /**
     * @return array{mime: string, ext: string}
     */
    private function validateImage(string $path): array
    {
        $fh = fopen($path, 'rb');
        if (false === $fh) {
            throw new BadRequestHttpException('Image file unreadable.');
        }
        $header = (string) fread($fh, 12);
        fclose($fh);
        if (str_starts_with($header, "\x89PNG\r\n\x1A\n")) {
            return ['mime' => 'image/png', 'ext' => 'png'];
        }
        if (str_starts_with($header, "\xFF\xD8\xFF")) {
            return ['mime' => 'image/jpeg', 'ext' => 'jpg'];
        }
        if (str_starts_with($header, 'GIF87a') || str_starts_with($header, 'GIF89a')) {
            return ['mime' => 'image/gif', 'ext' => 'gif'];
        }
        if (str_starts_with($header, 'RIFF') && 'WEBP' === substr($header, 8, 4)) {
            return ['mime' => 'image/webp', 'ext' => 'webp'];
        }

        throw new BadRequestHttpException('Image must be PNG, JPEG, GIF or WebP.');
    }
}
