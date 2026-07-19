<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Enum\ControlValidationMethod;
use App\Enum\EventType;
use App\Enum\Visibility;
use App\Repository\EventRepository;
use App\State\EventPersistProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Table(name: 'events')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        // Anonymous reads are allowed — the {@see \App\Doctrine\VisibilityExtension}
        // narrows the SQL to public events, and the slim `event:public` group
        // limits what flows out to the bare minimum the mobile app needs.
        new GetCollection(
            security: "is_granted('PUBLIC_ACCESS')",
            normalizationContext: ['groups' => ['event:public']],
        ),
        new Get(security: "is_granted('view', object)"),
        new Post(
            security: "is_granted('ROLE_USER')",
            processor: EventPersistProcessor::class,
        ),
        new Patch(
            security: "is_granted('manage', object)",
        ),
        new Delete(
            security: "is_granted('manage', object)",
        ),
    ],
    normalizationContext: ['groups' => ['event:read']],
    denormalizationContext: ['groups' => ['event:write']],
)]
class Event
{
    use IdentifiableTrait;
    use TimestampableTrait;

    #[ORM\Column(type: 'string', length: 200)]
    #[Assert\NotBlank]
    #[Groups(['event:read', 'event:write', 'event:public'])]
    private string $name;

    #[ORM\Column(type: 'string', length: 210, unique: true)]
    #[Gedmo\Slug(fields: ['name'])]
    #[Groups(['event:read', 'event:public'])]
    private ?string $slug = null;

    /**
     * Slug on the historical `api.orun.app` API — populated when the
     * event was imported from there. Null for events created directly
     * in the new backend. Unique partial index at DB level.
     */
    #[ORM\Column(type: 'string', length: 210, nullable: true)]
    #[Groups(['event:read'])]
    private ?string $legacySlug = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['event:read', 'event:write'])]
    private ?string $description = null;

    #[ORM\Column(type: 'string', length: 20, enumType: EventType::class)]
    #[Groups(['event:read', 'event:write', 'event:public'])]
    private EventType $type = EventType::Temporal;

    #[ORM\Column(type: 'string', length: 20, enumType: Visibility::class)]
    #[Groups(['event:read', 'event:write'])]
    private Visibility $visibility = Visibility::Public;

    /**
     * Required for Temporal events; optional for Permanent/Seasonal.
     */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['event:read', 'event:write'])]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['event:read', 'event:write'])]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column(type: 'string', length: 200, nullable: true)]
    #[Groups(['event:read', 'event:write', 'event:public'])]
    private ?string $location = null;

    #[ORM\Column(type: 'float', nullable: true)]
    #[Assert\Range(min: -90, max: 90)]
    #[Groups(['event:read', 'event:write'])]
    private ?float $latitude = null;

    #[ORM\Column(type: 'float', nullable: true)]
    #[Assert\Range(min: -180, max: 180)]
    #[Groups(['event:read', 'event:write'])]
    private ?float $longitude = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['event:read', 'event:write'])]
    private bool $showMap = true;

    /**
     * When false, the mobile hides the post-run rating dialog AND the
     * manager's feedback list stays empty (no new rows can be
     * created via the POST endpoint). Existing feedbacks aren't
     * deleted — turning the module back on brings them back into view.
     * Also serialised in `event:public` so the mobile knows whether
     * to render the rating dialog after a finish without a second
     * roundtrip.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['event:read', 'event:write', 'event:public'])]
    private bool $feedbackEnabled = true;

    /**
     * When false, the "Partager" section on the event detail page (both
     * manager AND mobile) is hidden. Useful for private events an
     * organiser doesn't want to see publicised.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['event:read', 'event:write', 'event:public'])]
    private bool $shareEnabled = true;

    /**
     * When false, the mobile hides the "Signaler ce poste" button on
     * the run screen AND the API refuses new control reports for this
     * event. Existing reports stay visible in the manager queue.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['event:read', 'event:write', 'event:public'])]
    private bool $controlReportsEnabled = true;

    /**
     * Cover photo used to illustrate the event in lists and headers.
     * Stored as a fully-qualified URL (the upload controller writes via
     * {@see \App\Service\MapStorage} which returns a public URL).
     * Null means "no upload yet"; the mobile app then falls back to a
     * deterministic random image keyed on the slug.
     */
    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    #[Groups(['event:read', 'event:public'])]
    private ?string $coverImageUrl = null;

    /**
     * Validation methods proposed by default whenever a new control is added
     * to this event. Stored as the backing values of
     * {@see ControlValidationMethod}. Same shape as Control.validationMethods
     * so the front can copy it straight onto the control form.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    #[Groups(['event:read', 'event:write'])]
    #[Assert\All([
        new Assert\Choice(callback: [ControlValidationMethod::class, 'values']),
    ])]
    private array $defaultValidationMethods = [];

    #[ORM\Column(type: 'boolean')]
    #[Groups(['event:read', 'event:write'])]
    private bool $published = false;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    #[Groups(['event:read', 'event:write'])]
    private ?Organization $organization = null;

    /**
     * The user who created the event. Always set server-side from the
     * authenticated user; never accepted from the client.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Groups(['event:read'])]
    private ?User $creator = null;

    /**
     * EXTRA_LAZY so {@see getCoursesCount()} translates to a `COUNT(*)` query
     * instead of hydrating every course of every event in the listing —
     * pagination would be untenable otherwise.
     *
     * @var Collection<int, Course>
     */
    #[ORM\OneToMany(mappedBy: 'event', targetEntity: Course::class, cascade: ['remove'], fetch: 'EXTRA_LAZY')]
    private Collection $courses;

    /**
     * @var Collection<int, Control>
     */
    #[ORM\OneToMany(mappedBy: 'event', targetEntity: Control::class, cascade: ['remove'])]
    private Collection $controls;

    public function __construct(string $name, EventType $type = EventType::Temporal)
    {
        $this->initializeUuid();
        // Funnel through the setter so the trim+capitalize normalization
        // applies even when the entity is built via the API Platform
        // denormalizer (which calls the constructor with the raw `name`
        // arg and would otherwise short-circuit the setter).
        $this->setName($name);
        $this->type = $type;
        $this->courses = new ArrayCollection();
        $this->controls = new ArrayCollection();
    }

    public function getType(): EventType
    {
        return $this->type;
    }

    public function setType(EventType $type): void
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

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Normalises the human-typed name: trims whitespace and capitalises
     * the first letter. Done here (not in a validator) so every entry
     * point — manager modal, mobile registration, fixtures, raw API POST —
     * stores the same shape without having to repeat the rule.
     */
    public function setName(string $name): void
    {
        $trimmed = trim($name);
        if ('' === $trimmed) {
            $this->name = $trimmed;

            return;
        }
        $this->name = mb_strtoupper(mb_substr($trimmed, 0, 1)).mb_substr($trimmed, 1);
    }

    public function getCoverImageUrl(): ?string
    {
        return $this->coverImageUrl;
    }

    public function setCoverImageUrl(?string $coverImageUrl): void
    {
        $this->coverImageUrl = $coverImageUrl;
    }

    public function getLegacySlug(): ?string
    {
        return $this->legacySlug;
    }

    public function setLegacySlug(?string $slug): void
    {
        $this->legacySlug = $slug;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTimeImmutable $startDate): void
    {
        $this->startDate = $startDate;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(?\DateTimeImmutable $endDate): void
    {
        $this->endDate = $endDate;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): void
    {
        $this->location = $location;
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function setLatitude(?float $latitude): void
    {
        $this->latitude = $latitude;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setLongitude(?float $longitude): void
    {
        $this->longitude = $longitude;
    }

    public function isShowMap(): bool
    {
        return $this->showMap;
    }

    public function setShowMap(bool $showMap): void
    {
        $this->showMap = $showMap;
    }

    public function isFeedbackEnabled(): bool
    {
        return $this->feedbackEnabled;
    }

    public function setFeedbackEnabled(bool $enabled): void
    {
        $this->feedbackEnabled = $enabled;
    }

    public function isShareEnabled(): bool
    {
        return $this->shareEnabled;
    }

    public function setShareEnabled(bool $enabled): void
    {
        $this->shareEnabled = $enabled;
    }

    public function isControlReportsEnabled(): bool
    {
        return $this->controlReportsEnabled;
    }

    public function setControlReportsEnabled(bool $enabled): void
    {
        $this->controlReportsEnabled = $enabled;
    }

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function setPublished(bool $published): void
    {
        $this->published = $published;
    }

    public function getOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function setOrganization(?Organization $organization): void
    {
        $this->organization = $organization;
    }

    /**
     * @return list<string>
     */
    public function getDefaultValidationMethods(): array
    {
        return $this->defaultValidationMethods;
    }

    /**
     * @param list<ControlValidationMethod|string> $methods
     */
    public function setDefaultValidationMethods(array $methods): void
    {
        $normalized = [];
        foreach ($methods as $method) {
            $value = $method instanceof ControlValidationMethod ? $method->value : (string) $method;
            if (!\in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }
        $this->defaultValidationMethods = array_values($normalized);
    }

    public function getCreator(): ?User
    {
        return $this->creator;
    }

    public function setCreator(User $creator): void
    {
        $this->creator = $creator;
    }

    /**
     * @return Collection<int, Course>
     */
    public function getCourses(): Collection
    {
        return $this->courses;
    }

    /**
     * Exposed in `event:public` so the mobile event list can show
     * "X circuits" without a second request. EXTRA_LAZY on the relation
     * means this is a single `COUNT(*)`.
     */
    #[Groups(['event:read', 'event:public'])]
    public function getCoursesCount(): int
    {
        return $this->courses->count();
    }

    /**
     * @return Collection<int, Control>
     */
    public function getControls(): Collection
    {
        return $this->controls;
    }
}
