<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\EventRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Table(name: 'events')]
#[ORM\HasLifecycleCallbacks]
class Event
{
    use IdentifiableTrait;
    use TimestampableTrait;

    #[ORM\Column(type: 'string', length: 200)]
    #[Assert\NotBlank]
    private string $name;

    #[ORM\Column(type: 'string', length: 210, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^[a-z0-9\-]+$/')]
    private string $slug;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $startDate;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $endDate;

    #[ORM\Column(type: 'string', length: 200, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(type: 'boolean')]
    private bool $published = false;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
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

    public function __construct(string $name, string $slug, Organization $organization, \DateTimeImmutable $startDate, \DateTimeImmutable $endDate)
    {
        $this->initializeUuid();
        $this->name = $name;
        $this->slug = $slug;
        $this->organization = $organization;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->courses = new ArrayCollection();
        $this->controls = new ArrayCollection();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(\DateTimeImmutable $startDate): void
    {
        $this->startDate = $startDate;
    }

    public function getEndDate(): \DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(\DateTimeImmutable $endDate): void
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
