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
        new Get(),
        new Post(
            security: "is_granted('ROLE_USER')",
            securityPostDenormalize: "is_granted('manage', object.getEvent().getOrganization())",
            securityPostDenormalizeMessage: 'You can only create courses in an event of an organization you manage.',
        ),
        new Patch(
            security: "is_granted('manage', object.getEvent().getOrganization())",
        ),
        new Delete(
            security: "is_granted('manage', object.getEvent().getOrganization())",
        ),
    ],
    normalizationContext: ['groups' => ['course:read']],
    denormalizationContext: ['groups' => ['course:write']],
)]
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

    public function getDistanceKm(): ?string
    {
        return $this->distanceKm;
    }

    public function setDistanceKm(?string $km): void
    {
        $this->distanceKm = $km;
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
