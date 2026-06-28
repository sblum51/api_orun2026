<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Enum\CourseType;
use App\Enum\Visibility;
use App\Repository\CourseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CourseRepository::class)]
#[ORM\Table(name: 'courses')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(security: "is_granted('view', object)"),
        new Post(
            security: "is_granted('ROLE_USER')",
            securityPostDenormalize: "is_granted('manage', object.getEvent())",
            securityPostDenormalizeMessage: 'You can only create courses in an event of an organization you manage.',
        ),
        new Patch(
            security: "is_granted('manage', object.getEvent())",
        ),
        new Delete(
            security: "is_granted('manage', object.getEvent())",
        ),
    ],
    normalizationContext: ['groups' => ['course:read']],
    denormalizationContext: ['groups' => ['course:write']],
)]
/*
 * Subresource: GET /api/events/{eventId}/courses — returns every Course of
 * an Event, server-side filtered (no pagination surprise on the manager
 * side). VisibilityExtension still applies so private courses stay hidden.
 *
 * API Platform 4 doesn't propagate the resource-level uriTemplate to
 * operations, so we declare it on the GetCollection itself.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/events/{eventId}/courses',
            uriVariables: [
                'eventId' => new Link(fromClass: Event::class, toProperty: 'event'),
            ],
            normalizationContext: ['groups' => ['course:read']],
        ),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['event' => 'exact'])]
class Course
{
    use IdentifiableTrait;
    use TimestampableTrait;

    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank]
    #[Groups(['course:read', 'course:write'])]
    private string $name;

    #[ORM\Column(type: 'string', length: 30, enumType: CourseType::class)]
    #[Groups(['course:read', 'course:write'])]
    private CourseType $type;

    #[ORM\Column(type: 'string', length: 20, enumType: Visibility::class)]
    #[Groups(['course:read', 'course:write'])]
    private Visibility $visibility = Visibility::Public;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\PositiveOrZero]
    #[Groups(['course:read', 'course:write'])]
    private ?int $durationLimitMin = null;

    #[ORM\Column(type: 'decimal', precision: 6, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero]
    #[Groups(['course:read', 'course:write'])]
    private ?string $distanceKm = null;

    /**
     * Total positive elevation gain (D+) in meters. Sourced from the IOF
     * XML `<Climb>` field at import time, editable manually after.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\PositiveOrZero]
    #[Groups(['course:read', 'course:write'])]
    private ?int $climbM = null;

    /**
     * When true, controls assigned to this course are expected to carry
     * latitude/longitude (and the manager UI shows the map for placement).
     * When false, controls are identified by code only.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['course:read', 'course:write'])]
    private bool $controlsGeolocated = true;

    /**
     * When true (default), the manager + mobile renderer draw the IOF
     * symbols (start triangle, control circles, finish double circle) and
     * the connecting line on top of the basemap. When false, the operator
     * has decided the GroundOverlay image already embeds the full circuit
     * graphics, so drawing them again would just clutter the map.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['course:read', 'course:write'])]
    private bool $drawCircuitOverlay = true;

    /**
     * Start triangle position. Distinct from regular controls — the start is
     * its own KMZ Placemark (typically id="S1") and has no orienteering code.
     */
    #[ORM\Column(type: 'float', nullable: true)]
    #[Assert\Range(min: -90, max: 90)]
    #[Groups(['course:read', 'course:write'])]
    private ?float $startLatitude = null;

    #[ORM\Column(type: 'float', nullable: true)]
    #[Assert\Range(min: -180, max: 180)]
    #[Groups(['course:read', 'course:write'])]
    private ?float $startLongitude = null;

    /**
     * Finish "double circle" position. Typically a separate KMZ Placemark
     * (e.g. id="F1"); a course can end at the same spot as its last control
     * but conceptually it's still its own point.
     */
    #[ORM\Column(type: 'float', nullable: true)]
    #[Assert\Range(min: -90, max: 90)]
    #[Groups(['course:read', 'course:write'])]
    private ?float $finishLatitude = null;

    #[ORM\Column(type: 'float', nullable: true)]
    #[Assert\Range(min: -180, max: 180)]
    #[Groups(['course:read', 'course:write'])]
    private ?float $finishLongitude = null;

    /**
     * @deprecated mirror of starts[0]/finishes[0] kept for back-compat;
     * the app reads the arrays first. Will be dropped once every client
     * is on the multi-S/F shape.
     */

    /**
     * Ordered list of Start points. The mobile app labels them S1, S2, …
     * by their index in this array (matches the IOF Placemark naming
     * convention). The legacy `startLatitude`/`startLongitude` mirror
     * `starts[0]` and stay in place for back-compat — both are written by
     * the importer; reads should prefer this array.
     *
     * Shape: `[{ "latitude": 48.85, "longitude": 2.35 }, ...]`
     *
     * @var list<array{latitude: float, longitude: float}>
     */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    #[Groups(['course:read', 'course:write'])]
    private array $starts = [];

    /**
     * Ordered list of Finish points (F1, F2, …). Same shape and same
     * mirroring rule as `$starts`.
     *
     * @var list<array{latitude: float, longitude: float}>
     */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    #[Groups(['course:read', 'course:write'])]
    private array $finishes = [];

    #[ORM\ManyToOne(targetEntity: Event::class, inversedBy: 'courses')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['course:read', 'course:write'])]
    private Event $event;

    /**
     * @var Collection<int, CourseControl>
     */
    #[ORM\OneToMany(mappedBy: 'course', targetEntity: CourseControl::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $courseControls;

    public function __construct(string $name, CourseType $type, Event $event)
    {
        $this->initializeUuid();
        $this->name = $name;
        $this->type = $type;
        $this->event = $event;
        $this->courseControls = new ArrayCollection();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getType(): CourseType
    {
        return $this->type;
    }

    public function setType(CourseType $type): void
    {
        $this->type = $type;
    }

    public function getVisibility(): Visibility
    {
        return $this->visibility;
    }

    public function setVisibility(Visibility $visibility): void
    {
        $this->visibility = $visibility;
    }

    public function getDurationLimitMin(): ?int
    {
        return $this->durationLimitMin;
    }

    public function setDurationLimitMin(?int $minutes): void
    {
        $this->durationLimitMin = $minutes;
    }

    public function getClimbM(): ?int
    {
        return $this->climbM;
    }

    public function setClimbM(?int $climbM): void
    {
        $this->climbM = $climbM;
    }

    public function getDistanceKm(): ?string
    {
        return $this->distanceKm;
    }

    public function setDistanceKm(?string $km): void
    {
        $this->distanceKm = $km;
    }

    public function isControlsGeolocated(): bool
    {
        return $this->controlsGeolocated;
    }

    public function setControlsGeolocated(bool $geolocated): void
    {
        $this->controlsGeolocated = $geolocated;
    }

    public function isDrawCircuitOverlay(): bool
    {
        return $this->drawCircuitOverlay;
    }

    public function setDrawCircuitOverlay(bool $drawCircuitOverlay): void
    {
        $this->drawCircuitOverlay = $drawCircuitOverlay;
    }

    public function getStartLatitude(): ?float
    {
        return $this->startLatitude;
    }

    public function setStartLatitude(?float $startLatitude): void
    {
        $this->startLatitude = $startLatitude;
    }

    public function getStartLongitude(): ?float
    {
        return $this->startLongitude;
    }

    public function setStartLongitude(?float $startLongitude): void
    {
        $this->startLongitude = $startLongitude;
    }

    public function getFinishLatitude(): ?float
    {
        return $this->finishLatitude;
    }

    public function setFinishLatitude(?float $finishLatitude): void
    {
        $this->finishLatitude = $finishLatitude;
    }

    public function getFinishLongitude(): ?float
    {
        return $this->finishLongitude;
    }

    public function setFinishLongitude(?float $finishLongitude): void
    {
        $this->finishLongitude = $finishLongitude;
    }

    /**
     * @return list<array{latitude: float, longitude: float}>
     */
    public function getStarts(): array
    {
        return $this->starts;
    }

    /**
     * @param list<array{latitude: float, longitude: float}> $starts
     */
    public function setStarts(array $starts): void
    {
        $this->starts = array_values($starts);
        // Mirror the first point to the legacy scalar columns so older code
        // and the manager's previous queries keep working until the full
        // migration to arrays is complete.
        if ([] !== $this->starts) {
            $this->startLatitude = $this->starts[0]['latitude'] ?? null;
            $this->startLongitude = $this->starts[0]['longitude'] ?? null;
        } else {
            $this->startLatitude = null;
            $this->startLongitude = null;
        }
    }

    /**
     * @return list<array{latitude: float, longitude: float}>
     */
    public function getFinishes(): array
    {
        return $this->finishes;
    }

    /**
     * @param list<array{latitude: float, longitude: float}> $finishes
     */
    public function setFinishes(array $finishes): void
    {
        $this->finishes = array_values($finishes);
        if ([] !== $this->finishes) {
            $this->finishLatitude = $this->finishes[0]['latitude'] ?? null;
            $this->finishLongitude = $this->finishes[0]['longitude'] ?? null;
        } else {
            $this->finishLatitude = null;
            $this->finishLongitude = null;
        }
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function setEvent(Event $event): void
    {
        $this->event = $event;
    }

    /**
     * @return Collection<int, CourseControl>
     */
    public function getCourseControls(): Collection
    {
        return $this->courseControls;
    }
}
