<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Enum\CourseType;
use App\Repository\CourseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CourseRepository::class)]
#[ORM\Table(name: 'courses')]
#[ORM\HasLifecycleCallbacks]
class Course
{
    use IdentifiableTrait;
    use TimestampableTrait;

    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank]
    private string $name;

    #[ORM\Column(type: 'string', length: 30, enumType: CourseType::class)]
    private CourseType $type;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $durationLimitMin = null;

    #[ORM\Column(type: 'decimal', precision: 6, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?string $distanceKm = null;

    #[ORM\ManyToOne(targetEntity: Event::class, inversedBy: 'courses')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
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
