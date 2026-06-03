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
use App\Enum\EventType;
use App\Enum\Visibility;
use App\Repository\EventRepository;
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
        new GetCollection(),
        new Get(),
        new Post(
            security: "is_granted('ROLE_USER')",
            securityPostDenormalize: "is_granted('manage', object.getOrganization())",
            securityPostDenormalizeMessage: 'You can only create events in an organization you manage.',
        ),
        new Patch(
            security: "is_granted('manage', object.getOrganization())",
        ),
        new Delete(
            security: "is_granted('manage', object.getOrganization())",
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
    #[Groups(['event:read', 'event:write'])]
    private string $name;

    #[ORM\Column(type: 'string', length: 210, unique: true)]
    #[Gedmo\Slug(fields: ['name'])]
    #[Groups(['event:read'])]
    private ?string $slug = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['event:read', 'event:write'])]
    private ?string $description = null;

    #[ORM\Column(type: 'string', length: 20, enumType: EventType::class)]
    #[Groups(['event:read', 'event:write'])]
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
    #[Groups(['event:read', 'event:write'])]
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

    #[ORM\Column(type: 'boolean')]
    #[Groups(['event:read', 'event:write'])]
    private bool $published = false;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Groups(['event:read', 'event:write'])]
    private Organization $organization;

    /**
     * @var Collection<int, Course>
     */
    #[ORM\OneToMany(mappedBy: 'event', targetEntity: Course::class, cascade: ['remove'])]
    private Collection $courses;

    /**
     * @var Collection<int, Control>
     */
    #[ORM\OneToMany(mappedBy: 'event', targetEntity: Control::class, cascade: ['remove'])]
    private Collection $controls;

    public function __construct(string $name, EventType $type = EventType::Temporal)
    {
        $this->initializeUuid();
        $this->name = $name;
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

    public function setName(string $name): void
    {
        $this->name = $name;
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

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function setPublished(bool $published): void
    {
        $this->published = $published;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function setOrganization(Organization $organization): void
    {
        $this->organization = $organization;
    }

    /**
     * @return Collection<int, Course>
     */
    public function getCourses(): Collection
    {
        return $this->courses;
    }

    /**
     * @return Collection<int, Control>
     */
    public function getControls(): Collection
    {
        return $this->controls;
    }
}
