<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\CourseControlRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CourseControlRepository::class)]
#[ORM\Table(name: 'course_controls')]
#[ORM\UniqueConstraint(name: 'course_controls_course_position_uniq', columns: ['course_id', 'position'])]
#[ORM\HasLifecycleCallbacks]
class CourseControl
{
    use IdentifiableTrait;
    use TimestampableTrait;

    #[ORM\Column(type: 'integer')]
    #[Assert\Positive]
    private int $position;

    /**
     * Score awarded when validating this control. Only used for CourseType::Score.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $score = null;

    /**
     * If true, this control must be validated by two team members together.
     * Only meaningful for CourseType::SharedRelay.
     */
    #[ORM\Column(type: 'boolean')]
    private bool $pairRequired = false;

    #[ORM\ManyToOne(targetEntity: Course::class, inversedBy: 'courseControls')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Course $course;

    #[ORM\ManyToOne(targetEntity: Control::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Control $control;

    public function __construct(Course $course, Control $control, int $position)
    {
        $this->initializeUuid();
        $this->course = $course;
        $this->control = $control;
        $this->position = $position;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function setScore(?int $score): void
    {
        $this->score = $score;
    }

    public function isPairRequired(): bool
    {
        return $this->pairRequired;
    }

    public function setPairRequired(bool $pairRequired): void
    {
        $this->pairRequired = $pairRequired;
    }

    public function getCourse(): Course
    {
        return $this->course;
    }

    public function getControl(): Control
    {
        return $this->control;
    }
}
