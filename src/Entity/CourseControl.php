<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\CourseControlRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CourseControlRepository::class)]
#[ORM\Table(name: 'course_controls')]
#[ORM\UniqueConstraint(name: 'course_controls_course_position_uniq', columns: ['course_id', 'position'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(
            security: "is_granted('ROLE_USER')",
            securityPostDenormalize: "is_granted('manage', object.getCourse().getEvent())",
            securityPostDenormalizeMessage: 'You can only attach controls to a course you manage.',
        ),
        new Patch(security: "is_granted('manage', object.getCourse().getEvent())"),
        new Delete(security: "is_granted('manage', object.getCourse().getEvent())"),
    ],
    normalizationContext: ['groups' => ['course_control:read']],
    denormalizationContext: ['groups' => ['course_control:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['course' => 'exact'])]
class CourseControl
{
    use IdentifiableTrait;
    use TimestampableTrait;

    #[ORM\Column(type: 'integer')]
    #[Assert\Positive]
    #[Groups(['course_control:read', 'course_control:write'])]
    private int $position;

    /**
     * Score awarded when validating this control. Only used for CourseType::Score.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\PositiveOrZero]
    #[Groups(['course_control:read', 'course_control:write'])]
    private ?int $score = null;

    /**
     * If true, this control must be validated by two team members together.
     * Only meaningful for CourseType::SharedRelay.
     */
    #[ORM\Column(type: 'boolean')]
    #[Groups(['course_control:read', 'course_control:write'])]
    private bool $pairRequired = false;

    #[ORM\ManyToOne(targetEntity: Course::class, inversedBy: 'courseControls')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    #[Groups(['course_control:read', 'course_control:write'])]
    private ?Course $course = null;

    #[ORM\ManyToOne(targetEntity: Control::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    #[Groups(['course_control:read', 'course_control:write'])]
    private ?Control $control = null;

    public function __construct(?Course $course = null, ?Control $control = null, ?int $position = null)
    {
        $this->initializeUuid();
        if (null !== $course) {
            $this->course = $course;
        }
        if (null !== $control) {
            $this->control = $control;
        }
        if (null !== $position) {
            $this->position = $position;
        }
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

    public function getCourse(): ?Course
    {
        return $this->course;
    }

    public function setCourse(Course $course): void
    {
        $this->course = $course;
    }

    public function getControl(): ?Control
    {
        return $this->control;
    }

    public function setControl(Control $control): void
    {
        $this->control = $control;
    }
}
