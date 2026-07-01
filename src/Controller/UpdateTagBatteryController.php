<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Tag;
use App\Enum\TagType;
use App\Repository\TagRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Bulk endpoint the mobile app hits when it detects iBeacon battery
 * levels during a scan (tag-library flow) or a run.
 *
 * Payload:
 *   {
 *     "observations": [
 *       { "uuid": "f7826da6-…", "major": 41, "minor": 141, "battery": 87 },
 *       ...
 *     ]
 *   }
 *
 * For each observation we look up an iBeacon-typed Tag matching all three
 * identifiers (uuid + major + minor) in its payload. If the current user
 * has `manage` permission (via the TagVoter — creator or org member), the
 * tag's payload gets `battery` + `batteryAt` refreshed. Others are
 * silently skipped: no error, no info leak, just no update.
 *
 * The response returns the count of successfully updated tags so the
 * client can trace throttling logic without asking for individual IDs
 * back (which would require an extra roundtrip and leak tag existence).
 */
final class UpdateTagBatteryController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TagRepository $tagRepository,
        private readonly Security $security,
    ) {
    }

    #[Route(
        path: '/api/tags/battery-updates',
        name: 'api_tags_battery_updates',
        methods: ['POST'],
        format: 'json',
    )]
    #[IsGranted('ROLE_USER')]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true);
        $observations = $payload['observations'] ?? null;
        if (!\is_array($observations)) {
            throw new BadRequestHttpException('Body must be `{ "observations": [...] }`.');
        }
        if (\count($observations) > 100) {
            throw new BadRequestHttpException('At most 100 observations per request.');
        }

        $updated = 0;
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        foreach ($observations as $obs) {
            if (!\is_array($obs)) {
                continue;
            }
            $uuid = $obs['uuid'] ?? null;
            $major = $obs['major'] ?? null;
            $minor = $obs['minor'] ?? null;
            $battery = $obs['battery'] ?? null;
            if (!\is_string($uuid) || !\is_int($major) || !\is_int($minor) || !\is_int($battery)) {
                continue;
            }
            if ($battery < 0 || $battery > 100) {
                continue;
            }

            // Postgres JSONB match: `payload @> '{"uuid":"…","major":…,"minor":…}'`.
            // We drop to native SQL because Doctrine's DQL has no operator
            // for `@>` — DBAL binds params fine and the query hits the
            // JSONB GIN index on `payload` (if present) directly.
            $matcher = json_encode([
                'uuid' => strtolower($uuid),
                'major' => $major,
                'minor' => $minor,
            ], \JSON_THROW_ON_ERROR);

            /** @var Connection $conn */
            $conn = $this->em->getConnection();
            $rows = $conn->executeQuery(
                'SELECT id FROM tags WHERE type = :type AND payload @> :matcher::jsonb',
                ['type' => TagType::IBeacon->value, 'matcher' => $matcher],
            )->fetchAllAssociative();

            if ([] === $rows) {
                continue;
            }
            $tags = $this->tagRepository->findBy([
                'id' => array_column($rows, 'id'),
            ]);

            foreach ($tags as $tag) {
                \assert($tag instanceof Tag);
                if (!$this->security->isGranted('manage', $tag)) {
                    continue;
                }
                $current = $tag->getPayload();
                $current['battery'] = $battery;
                $current['batteryAt'] = $now;
                $tag->setPayload($current);
                ++$updated;
            }
        }

        $this->em->flush();

        return new JsonResponse(['updated' => $updated]);
    }
}
