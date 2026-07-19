<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Control;
use App\Entity\Course;
use App\Entity\CourseControl;
use App\Entity\Event;
use App\Entity\Map;
use App\Entity\User;
use App\Enum\ControlValidationMethod;
use App\Enum\CourseType;
use App\Enum\EventType;
use App\Repository\EventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Materialises a legacy event (fetched from api.orun.app) into the
 * new backend's schema.
 *
 * Idempotency: keyed on `Event.legacy_slug`. A second call with the
 * same slug UPDATES the existing rows instead of creating duplicates.
 * Course upsert is keyed by name-within-event (the legacy API doesn't
 * expose stable UUIDs for course rows).
 *
 * The mapping is best-effort: the legacy shape has evolved over years
 * and some fields don't have exact counterparts. Missing pieces
 * default to sensible values (Classic course, GPS validation method).
 * Fields we can't confidently guess are left blank so the operator
 * can polish them post-import.
 */
final class LegacyImporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventRepository $events,
        private readonly LegacyOrunClient $legacy,
        private readonly MapStorage $storage,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{
     *   event: Event,
     *   coursesCreated: int,
     *   coursesUpdated: int,
     *   controlsCreated: int,
     *   mapsImported: int,
     * }
     */
    public function importBySlug(string $legacySlug, User $actor): array
    {
        $payload = $this->legacy->getEventBySlug($legacySlug);

        $event = $this->events->findOneBy(['legacySlug' => $legacySlug])
            ?? new Event(
                $this->str($payload, 'name', 'Évènement importé') ?? 'Évènement importé',
                EventType::Temporal,
            );
        $event->setLegacySlug($legacySlug);
        // We call the setter without a null check so both create AND
        // update paths converge — every field is refreshed from the
        // legacy payload on every re-import.
        $event->setName($this->str($payload, 'name', $event->getName()));
        if (($description = $this->str($payload, 'description', null)) !== null) {
            $event->setDescription($description);
        }
        if (($location = $this->str($payload, 'location', null)) !== null) {
            $event->setLocation($location);
        }
        if (($startDate = $this->date($payload, 'startDate')) !== null) {
            $event->setStartDate($startDate);
        }
        if (($endDate = $this->date($payload, 'endDate')) !== null) {
            $event->setEndDate($endDate);
        }
        if (($lat = $this->numeric($payload, 'latitude')) !== null) {
            $event->setLatitude($lat);
        }
        if (($lng = $this->numeric($payload, 'longitude')) !== null) {
            $event->setLongitude($lng);
        }
        // Ownership: creator is the caller if the row is new; existing
        // events keep their previous creator to avoid stealing ownership
        // on re-import.
        if ($event->getCreator() === null) {
            $event->setCreator($actor);
        }
        // Cover image, best-effort.
        $coverUrl = $this->str($payload, 'coverImageUrl', null)
            ?? $this->str($payload, 'illustrationUrl', null);
        if ($coverUrl !== null && $event->getCoverImageUrl() === null) {
            $stored = $this->rehostAsset($coverUrl, sprintf(
                'events/%s/legacy-cover',
                $legacySlug,
            ));
            if ($stored !== null) {
                $event->setCoverImageUrl($stored);
            }
        }
        $this->em->persist($event);
        // Flush now so the Event has an id for FKs on Course/Control.
        $this->em->flush();

        $result = [
            'event' => $event,
            'coursesCreated' => 0,
            'coursesUpdated' => 0,
            'controlsCreated' => 0,
            'mapsImported' => 0,
        ];

        $courses = $payload['courses'] ?? [];
        if (\is_array($courses)) {
            foreach ($courses as $legacyCourse) {
                if (!\is_array($legacyCourse)) {
                    continue;
                }
                $this->importCourse($event, $legacyCourse, $result);
            }
        }
        $this->em->flush();
        return $result;
    }

    /**
     * @param array<string, mixed> $legacyCourse
     * @param array<string, mixed> $result
     */
    private function importCourse(Event $event, array $legacyCourse, array &$result): void
    {
        $name = $this->str($legacyCourse, 'name', 'Circuit sans nom');
        $type = $this->mapCourseType($this->str($legacyCourse, 'type', null));

        $existing = null;
        foreach ($event->getCourses() as $c) {
            if ($c->getName() === $name) {
                $existing = $c;
                break;
            }
        }
        if ($existing === null) {
            $existing = new Course($name, $type, $event);
            $this->em->persist($existing);
            ++$result['coursesCreated'];
        } else {
            ++$result['coursesUpdated'];
        }

        if (($climb = $this->intOrNull($legacyCourse, 'climbM') ?? $this->intOrNull($legacyCourse, 'climb')) !== null) {
            $existing->setClimbM($climb);
        }
        if (($dist = $this->str($legacyCourse, 'distanceKm', null)) !== null) {
            $existing->setDistanceKm($dist);
        }

        // Controls — the legacy shape either embeds them or serialises
        // as a nested `courseControls` (with position + control body).
        $controlsSource = $legacyCourse['controls']
            ?? $legacyCourse['courseControls']
            ?? [];
        if (\is_array($controlsSource)) {
            $position = 0;
            foreach ($controlsSource as $legacyCtrl) {
                if (!\is_array($legacyCtrl)) {
                    continue;
                }
                // If the legacy row is a wrapper `{position, control:
                // {…}}`, unwrap.
                $control = \is_array($legacyCtrl['control'] ?? null)
                    ? $legacyCtrl['control']
                    : $legacyCtrl;

                $code = (string) ($control['code'] ?? $control['number'] ?? '');
                if ($code === '') {
                    continue;
                }
                $lat = $this->numeric($control, 'latitude');
                $lng = $this->numeric($control, 'longitude');
                $methods = $this->mapMethods($control['validationMethods'] ?? null);

                $ctrl = new Control($event, $code, $methods);
                if ($lat !== null) {
                    $ctrl->setLatitude($lat);
                }
                if ($lng !== null) {
                    $ctrl->setLongitude($lng);
                }
                $this->em->persist($ctrl);
                ++$result['controlsCreated'];

                $cc = new CourseControl($existing, $ctrl, ++$position);
                $this->em->persist($cc);
            }
        }

        // Map image, if any — legacy exposes it under either
        // `mapImageUrl`, `mapUrl`, or nested `maps[0].imageUrl`.
        $mapUrls = [];
        foreach (['mapImageUrl', 'mapUrl'] as $k) {
            $u = $this->str($legacyCourse, $k, null);
            if ($u !== null) {
                $mapUrls[] = $u;
            }
        }
        if (\is_array($legacyCourse['maps'] ?? null)) {
            foreach ($legacyCourse['maps'] as $legacyMap) {
                if (\is_array($legacyMap) && \is_string($legacyMap['imageUrl'] ?? null)) {
                    $mapUrls[] = $legacyMap['imageUrl'];
                }
            }
        }
        $mapUrls = array_values(array_unique($mapUrls));
        foreach ($mapUrls as $i => $mapUrl) {
            $stored = $this->rehostAsset($mapUrl, sprintf(
                'events/%s/legacy-map-%s-%d',
                $event->getLegacySlug() ?? 'unknown',
                $this->slugify($name),
                $i,
            ));
            if ($stored === null) {
                continue;
            }
            $map = new Map(
                $existing,
                sprintf('%s%s', $name, $i > 0 ? sprintf(' — carte %d', $i + 1) : ''),
                $stored,
            );
            $this->em->persist($map);
            ++$result['mapsImported'];
        }
    }

    private function mapCourseType(?string $legacy): CourseType
    {
        return match (strtolower((string) $legacy)) {
            'score', 'points' => CourseType::Score,
            'shared_relay', 'sharedrelay', 'relay', 'relais' => CourseType::SharedRelay,
            'tourist', 'touristic', 'touristique' => CourseType::Tourist,
            default => CourseType::Classic,
        };
    }

    /**
     * @return list<ControlValidationMethod>
     */
    private function mapMethods(mixed $legacy): array
    {
        if (!\is_array($legacy)) {
            return [ControlValidationMethod::Gps];
        }
        $out = [];
        foreach ($legacy as $m) {
            $method = match (strtolower((string) $m)) {
                'qr', 'qr_code', 'qrcode' => ControlValidationMethod::QrCode,
                'nfc' => ControlValidationMethod::Nfc,
                'ibeacon', 'beacon', 'ble' => ControlValidationMethod::IBeacon,
                'gps' => ControlValidationMethod::Gps,
                default => null,
            };
            if ($method !== null && !\in_array($method, $out, true)) {
                $out[] = $method;
            }
        }
        return $out !== [] ? $out : [ControlValidationMethod::Gps];
    }

    private function rehostAsset(string $legacyUrl, string $keyPrefix): ?string
    {
        $downloaded = $this->legacy->downloadAsset($legacyUrl);
        if ($downloaded === null) {
            $this->logger->warning('Legacy asset skipped', ['url' => $legacyUrl]);
            return null;
        }
        try {
            $hash = sha1_file($downloaded['path']) ?: bin2hex(random_bytes(16));
            $key = sprintf('%s-%s.%s', $keyPrefix, substr($hash, 0, 12), $downloaded['ext']);
            return $this->storage->storeFile($key, $downloaded['path'], $downloaded['mime']);
        } finally {
            @unlink($downloaded['path']);
        }
    }

    private function str(array $arr, string $key, ?string $default): ?string
    {
        $v = $arr[$key] ?? null;
        return \is_string($v) && $v !== '' ? $v : $default;
    }

    private function numeric(array $arr, string $key): ?float
    {
        $v = $arr[$key] ?? null;
        return \is_numeric($v) ? (float) $v : null;
    }

    private function intOrNull(array $arr, string $key): ?int
    {
        $v = $arr[$key] ?? null;
        return \is_numeric($v) ? (int) $v : null;
    }

    private function date(array $arr, string $key): ?\DateTimeImmutable
    {
        $v = $arr[$key] ?? null;
        if (!\is_string($v) || $v === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($v);
        } catch (\Throwable) {
            return null;
        }
    }

    private function slugify(string $s): string
    {
        $s = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s));
        $s = preg_replace('/[^a-z0-9]+/', '-', (string) $s);
        return trim((string) $s, '-') ?: 'x';
    }
}
