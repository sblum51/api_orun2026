<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\FeedbackRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Post-run rating from a runner. One row per Activity (unique index),
 * so `POST` upserts and `PATCH` updates the same record. Not exposed
 * as an ApiResource because the auth flow is custom — the two custom
 * controllers (`SubmitFeedbackController` + `EventFeedbacksController`)
 * fully cover the read + write surface.
 */
#[ORM\Entity(repositoryClass: FeedbackRepository::class)]
#[ORM\Table(name: 'feedbacks')]
#[ORM\HasLifecycleCallbacks]
class Feedback
{
    use IdentifiableTrait;
    use TimestampableTrait;

    #[ORM\OneToOne(targetEntity: Activity::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Activity $activity;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Range(min: 1, max: 5)]
    private int $rating;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $comment = null;

    public function __construct(Activity $activity, int $rating, ?string $comment = null)
    {
        $this->initializeUuid();
        $this->activity = $activity;
        $this->rating = $rating;
        $this->comment = $comment;
    }

    public function getActivity(): Activity { return $this->activity; }
    public function getRating(): int { return $this->rating; }
    public function getComment(): ?string { return $this->comment; }

    public function update(int $rating, ?string $comment): void
    {
        $this->rating = $rating;
        $this->comment = $comment;
    }
}
