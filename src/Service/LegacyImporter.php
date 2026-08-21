<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Control;
use App\Entity\Course;
use App\Entity\CourseControl;
use App\Entity\Event;
use App\Entity\Map;
use App\Entity\User;
use App\Enum\ControlType;
use App\Enum\ControlValidationMethod;
use App\Enum\CourseType;
use App\Enum\EventType;
use App\Repository\EventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Materialises a legacy event (fetched from api.orun.app) into the
 * new backend's schema. Idempotency is keyed on `Event.legacy_slug`:
 * a second call with the same slug rebuilds children (controls,
 * course_controls, maps) from the legacy payload — legacy-imported
 * events aren't meant to be hand-edited between imports.
 *
 * Legacy JSON shape (probed on
 * https://api.orun.app/events/event-france-oise-mogneville-60140):
 *
 *   {
 *     name, slug, description, city, zipcode, country, type,
 *     location: { geo: { lat, lng } },
 *     controls: [                        // event-level, canonical
 *       { id: "45" | "S1" | "F1",
 *         position: { lat, lng },
 *         mapPosition: { x, y },
 *         methods: [{ type: "gps", range: 10 },
 *                   { type: "qrcode", value: "TOKEN" },
 *                   { type: "nfc" | "beacon", ... }] }
 *     ],
 *     courses: [
 *       { name, type: "classic"|"score"|..., length, climb,
 *         start:  { id: "S1", legLength? },
 *         finish: { id: "F1", legLength? },
 *         controls: [ { id: "45", order: 1, legLength: "20" }, … ],
 *         maps: [ { filePath, mimeType, coordinates: LatLonBox } ] }
 *     ]
 *   }
 *
 * Course controls reference event.controls by id only — sequence is
 * [start.id, ...controls sorted by order, finish.id]. Maps hosted at
 * https://cdn.orun.app/maps/{filePath}.
 */
final class LegacyImporter
{
    private const MAP_CDN_BASE = 'https://cdn.orun.app/maps/';

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
        $event->setName($this->str($payload, 'name', $event->getName()) ?? $event->getName());
        if (($description = $this->str($payload, 'description', null)) !== null) {
            $event->setDescription($description);
        }
        // Legacy composes location as separate fields; the new backend
        // has a single string. Join what's there.
        $city = $this->str($payload, 'city', null);
        $zip = $this->str($payload, 'zipcode', null);
        $country = $this->str($payload, 'country', null);
        $locationBits = array_filter([
            trim(($zip ?? '') . ' ' . ($city ?? '')) ?: null,
            $country,
        ]);
        if ($locationBits !== []) {
            $event->setLocation(implode(', ', $locationBits));
        }
        // Event GPS lives under location.geo.{lat,lng}
        $geo = \is_array($payload['location'] ?? null) ? ($payload['location']['geo'] ?? null) : null;
        if (\is_array($geo)) {
            if (($lat = $this->numeric($geo, 'lat')) !== null) {
                $event->setLatitude($lat);
            }
            if (($lng = $this->numeric($geo, 'lng')) !== null) {
                $event->setLongitude($lng);
            }
        }
        if ($event->getCreator() === null) {
            $event->setCreator($actor);
        }
        $this->em->persist($event);
        $this->em->flush();

        // Idempotency: wipe legacy-derived children so re-import is a
        // clean rebuild. Doctrine cascades course_controls via the FK's
        // ON DELETE CASCADE; we still remove them explicitly so the
        // in-session UnitOfWork stays consistent.
        $this->wipeChildren($event);

        $result = [
            'event' => $event,
            'coursesCreated' => 0,
            'coursesUpdated' => 0,
            'controlsCreated' => 0,
            'mapsImported' => 0,
        ];

        // Materialise event-level controls first, indexed by legacy id
        // so course.controls[*].id / course.start.id / course.finish.id
        // can point at them.
        /** @var array<string, Control> $controlsByLegacyId */
        $controlsByLegacyId = [];
        $eventControls = $payload['controls'] ?? [];
        if (\is_array($eventControls)) {
            foreach ($eventControls as $legacyCtrl) {
                if (!\is_array($legacyCtrl)) {
                    continue;
                }
                $legacyId = (string) ($legacyCtrl['id'] ?? '');
                if ($legacyId === '') {
                    continue;
                }
                $ctrl = $this->buildControl($event, $legacyCtrl);
                $controlsByLegacyId[$legacyId] = $ctrl;
                $this->em->persist($ctrl);
                ++$result['controlsCreated'];
            }
        }

        $courses = $payload['courses'] ?? [];
        if (\is_array($courses)) {
            foreach ($courses as $legacyCourse) {
                if (!\is_array($legacyCourse)) {
                    continue;
                }
                $this->importCourse($event, $legacyCourse, $controlsByLegacyId, $result);
            }
        }
        $this->em->flush();
        return $result;
    }

    private function wipeChildren(Event $event): void
    {
        $conn = $this->em->getConnection();
        // course_controls first (FK to courses AND controls),
        // then maps (FK to courses), then controls.
        $conn->executeStatement(
            'DELETE FROM course_controls WHERE course_id IN (SELECT id FROM courses WHERE event_id = :eid)',
            ['eid' => $event->getId()->toRfc4122()],
        );
        $conn->executeStatement(
            'DELETE FROM maps WHERE course_id IN (SELECT id FROM courses WHERE event_id = :eid)',
            ['eid' => $event->getId()->toRfc4122()],
        );
        $conn->executeStatement(
            'DELETE FROM controls WHERE event_id = :eid',
            ['eid' => $event->getId()->toRfc4122()],
        );
    }

    /**
     * @param array<string, mixed> $legacyCtrl
     */
    private function buildControl(Event $event, array $legacyCtrl): Control
    {
        $legacyId = (string) ($legacyCtrl['id'] ?? '');
        $type = $this->controlTypeFromId($legacyId);

        $methodsRaw = \is_array($legacyCtrl['methods'] ?? null) ? $legacyCtrl['methods'] : [];
        [$methods, $payload] = $this->mapMethods($methodsRaw);
        // A control MUST have at least one validation method — legacy
        // occasionally omits them for start/finish; default to GPS then.
        if ($methods === []) {
            $methods = [ControlValidationMethod::Gps];
        }

        // Regular postes use a numeric code; start/finish use the raw
        // id (S1, F1). Constructor validates numeric range only for
        // type=Control, so we set type post-construction to bypass it.
        $ctrl = new Control($event, $legacyId, $methods);
        if ($type !== ControlType::Control) {
            $ctrl->setType($type);
            $ctrl->setCode($legacyId);
        }

        $pos = \is_array($legacyCtrl['position'] ?? null) ? $legacyCtrl['position'] : null;
        if ($pos !== null) {
            if (($lat = $this->numeric($pos, 'lat')) !== null) {
                $ctrl->setLatitude($lat);
            }
            if (($lng = $this->numeric($pos, 'lng')) !== null) {
                $ctrl->setLongitude($lng);
            }
        }
        if ($payload !== []) {
            $ctrl->setPayload($payload);
        }
        return $ctrl;
    }

    /**
     * @param array<string, mixed>               $legacyCourse
     * @param array<string, Control>             $controlsByLegacyId
     * @param array{event: Event, coursesCreated: int, coursesUpdated: int, controlsCreated: int, mapsImported: int} $result
     */
    private function importCourse(
        Event $event,
        array $legacyCourse,
        array $controlsByLegacyId,
        array &$result,
    ): void {
        $name = $this->str($legacyCourse, 'name', 'Circuit sans nom') ?? 'Circuit sans nom';
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

        if (($climb = $this->intOrNull($legacyCourse, 'climb')) !== null) {
            $existing->setClimbM($climb);
        }
        // Legacy `length` is in metres, our field is decimal km.
        if (($lengthM = $this->numeric($legacyCourse, 'length')) !== null) {
            $existing->setDistanceKm(number_format($lengthM / 1000, 3, '.', ''));
        }

        // Build the ordered sequence: [start, ...controls-by-order, finish]
        $sequence = [];
        $startId = \is_array($legacyCourse['start'] ?? null)
            ? (string) ($legacyCourse['start']['id'] ?? '')
            : '';
        if ($startId !== '' && isset($controlsByLegacyId[$startId])) {
            $sequence[] = $controlsByLegacyId[$startId];
        }

        $ordered = [];
        if (\is_array($legacyCourse['controls'] ?? null)) {
            foreach ($legacyCourse['controls'] as $ref) {
                if (!\is_array($ref)) {
                    continue;
                }
                $order = $this->intOrNull($ref, 'order') ?? \PHP_INT_MAX;
                $refId = (string) ($ref['id'] ?? '');
                if ($refId === '' || !isset($controlsByLegacyId[$refId])) {
                    continue;
                }
                $ordered[] = [$order, $controlsByLegacyId[$refId]];
            }
            usort($ordered, static fn ($a, $b) => $a[0] <=> $b[0]);
            foreach ($ordered as [, $ctrl]) {
                $sequence[] = $ctrl;
            }
        }

        $finishId = \is_array($legacyCourse['finish'] ?? null)
            ? (string) ($legacyCourse['finish']['id'] ?? '')
            : '';
        if ($finishId !== '' && isset($controlsByLegacyId[$finishId])) {
            $sequence[] = $controlsByLegacyId[$finishId];
        }

        $position = 0;
        foreach ($sequence as $ctrl) {
            $cc = new CourseControl($existing, $ctrl, ++$position);
            $this->em->persist($cc);
        }

        // Maps — legacy course.maps[].filePath, hosted on cdn.orun.app.
        if (\is_array($legacyCourse['maps'] ?? null)) {
            $i = 0;
            foreach ($legacyCourse['maps'] as $legacyMap) {
                if (!\is_array($legacyMap)) {
                    continue;
                }
                $filePath = $this->str($legacyMap, 'filePath', null);
                if ($filePath === null) {
                    continue;
                }
                $mapUrl = self::MAP_CDN_BASE . ltrim($filePath, '/');
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
                // Géoref legacy : `coordinates.LatLonBox` — sans les
                // bounds, le composant ControlsMap ignore silencieusement
                // la carte (uniqueOverlays skip if !m.bounds).
                $coords = \is_array($legacyMap['coordinates'] ?? null) ? $legacyMap['coordinates'] : null;
                if ($coords !== null) {
                    $bounds = [];
                    foreach (['north', 'south', 'east', 'west', 'rotation'] as $k) {
                        if (isset($coords[$k]) && \is_numeric($coords[$k])) {
                            $bounds[$k] = (float) $coords[$k];
                        }
                    }
                    if (isset($bounds['north'], $bounds['south'], $bounds['east'], $bounds['west'])) {
                        $map->setBounds($bounds);
                    }
                }
                $this->em->persist($map);
                ++$result['mapsImported'];
                ++$i;
            }
        }
    }

    private function controlTypeFromId(string $legacyId): ControlType
    {
        $first = strtoupper(substr($legacyId, 0, 1));
        return match ($first) {
            'S' => ControlType::Start,
            'F' => ControlType::Finish,
            default => ControlType::Control,
        };
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
     * Legacy `methods` is a list of `{type, ...extras}` objects. We
     * project the type onto our enum AND collect any per-method extras
     * (QR token, iBeacon major/minor, GPS range) into a payload map so
     * the mobile client can validate against them.
     *
     * @param list<mixed> $legacy
     * @return array{0: list<ControlValidationMethod>, 1: array<string, mixed>}
     */
    private function mapMethods(array $legacy): array
    {
        $out = [];
        $payload = [];
        foreach ($legacy as $m) {
            if (!\is_array($m)) {
                continue;
            }
            $t = strtolower((string) ($m['type'] ?? ''));
            $method = match ($t) {
                'qr', 'qr_code', 'qrcode' => ControlValidationMethod::QrCode,
                'nfc' => ControlValidationMethod::Nfc,
                'ibeacon', 'beacon', 'ble' => ControlValidationMethod::IBeacon,
                'gps' => ControlValidationMethod::Gps,
                default => null,
            };
            if ($method === null || \in_array($method, $out, true)) {
                continue;
            }
            $out[] = $method;
            switch ($method) {
                case ControlValidationMethod::QrCode:
                    if (isset($m['value'])) {
                        $payload['qr'] = ['token' => (string) $m['value']];
                    }
                    break;
                case ControlValidationMethod::Gps:
                    if (isset($m['range']) && \is_numeric($m['range'])) {
                        $payload['gps'] = ['rangeMeters' => (int) $m['range']];
                    }
                    break;
                case ControlValidationMethod::IBeacon:
                    $beacon = [];
                    foreach (['uuid', 'major', 'minor'] as $k) {
                        if (isset($m[$k])) {
                            $beacon[$k] = $m[$k];
                        }
                    }
                    if ($beacon !== []) {
                        $payload['ibeacon'] = $beacon;
                    }
                    break;
                case ControlValidationMethod::Nfc:
                    if (isset($m['value'])) {
                        $payload['nfc'] = ['tag' => (string) $m['value']];
                    }
                    break;
            }
        }
        return [$out, $payload];
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

    /**
     * @param array<string, mixed> $arr
     */
    private function str(array $arr, string $key, ?string $default): ?string
    {
        $v = $arr[$key] ?? null;
        return \is_string($v) && $v !== '' ? $v : $default;
    }

    /**
     * @param array<string, mixed> $arr
     */
    private function numeric(array $arr, string $key): ?float
    {
        $v = $arr[$key] ?? null;
        return \is_numeric($v) ? (float) $v : null;
    }

    /**
     * @param array<string, mixed> $arr
     */
    private function intOrNull(array $arr, string $key): ?int
    {
        $v = $arr[$key] ?? null;
        return \is_numeric($v) ? (int) $v : null;
    }

    private function slugify(string $s): string
    {
        $s = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim((string) $s, '-') ?: 'x';
    }
}
