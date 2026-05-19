<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Enum\ActivityStatus;
use App\Repository\ActivityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ActivityRepository::class)]
#[ORM\Table(name: 'activities')]
#[ORM\HasLifecycleCallbacks]
class Activity
{
    use IdentifiableTrait;
    use TimestampableTrait;

    #[ORM\Column(type: 'string', length: 20, enumType: ActivityStatus::class)]
    private ActivityStatus $status = ActivityStatus::Running;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /**
     * Cached total duration in seconds when the activity completes.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $totalDurationSec = null;

    /**
     * Score for CourseType::Score (cached after completion).
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $totalScore = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Course::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Course $course;

    #[ORM\ManyToOne(targetEntity: Team::class, inversedBy: 'activities')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Team $team = null;

    /**
     * @var Collection<int, Punch>
     */
    #[ORM\OneToMany(mappedBy: 'activity', targetEntity: Punch::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['punchedAt' => 'ASC'])]
    private Collection $punches;

    public function __construct(User $user, Course $course, \DateTimeImmutable $startedAt)
    {
        $this->initializeUuid();
        $this->user = $user;
        $this->course = $course;
        $this->startedAt = $startedAt;
        $this->punches = new ArrayCollection();
    }

    public function getStatus(): ActivityStatus
    {
        return $this->status;
    }

    public function setStatus(ActivityStatus $status): void
    {
        $this->status = $status;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): void
    {
        $this->finishedAt = $finishedAt;
    }

    public function getTotalDurationSec(): ?int
    {
        return $this->totalDurationSec;
    }

    public function setTotalDurationSec(?int $seconds): void
    {
        $this->totalDurationSec = $seconds;
    }

    public function getTotalScore(): ?int
    {
        return $this->totalScore;
    }

    public function setTotalScore(?int $score): void
    {
        $this->totalScore = $score;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCourse(): Course
    {
        return $this->course;
    }

    public function getTeam(): ?Team
    {
        return $this->team;
    }

    public function setTeam(?Team $team): void
    {
        $this->team = $team;
    }

    /**
     * @return Collection<int, Punch>
     */
    public function getPunches(): Collection
    {
        return $this->punches;
    }
}
