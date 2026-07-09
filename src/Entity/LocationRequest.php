<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiableTrait;
use App\Enum\LocationRequestReason;
use App\Repository\LocationRequestRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Audit row for every "locate this runner" action fired by an
 * organiser. Immutable once created — no update flow — but
 * `answeredAt` is bumped when the runner's app responds with a fresh
 * position, so the manager UI can show "answered in 4 s" vs "no
 * response for 3 min".
 *
 * The runner can review these entries via
 * `GET /api/activities/{id}/location-requests` (transparency clause).
 */
#[ORM\Entity(repositoryClass: LocationRequestRepository::class)]
#[ORM\Table(name: 'location_requests')]
class LocationRequest
{
    use IdentifiableTrait;

    #[ORM\ManyToOne(targetEntity: Activity::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Activity $activity;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'requested_by_user_id', nullable: false, onDelete: 'RESTRICT')]
    private User $requestedBy;

    #[ORM\Column(type: 'string', length: 20, enumType: LocationRequestReason::class)]
    private LocationRequestReason $reason;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $freeText = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $answeredAt = null;

    public function __construct(
        Activity $activity,
        User $requestedBy,
        LocationRequestReason $reason,
        ?string $freeText = null,
    ) {
        $this->initializeUuid();
        $this->activity = $activity;
        $this->requestedBy = $requestedBy;
        $this->reason = $reason;
        $this->freeText = $freeText;
        $this->requestedAt = new \DateTimeImmutable();
    }

    public function getActivity(): Activity { return $this->activity; }
    public function getRequestedBy(): User { return $this->requestedBy; }
    public function getReason(): LocationRequestReason { return $this->reason; }
    public function getFreeText(): ?string { return $this->freeText; }
    public function getRequestedAt(): \DateTimeImmutable { return $this->requestedAt; }
    public function getAnsweredAt(): ?\DateTimeImmutable { return $this->answeredAt; }

    public function markAnswered(): void
    {
        if ($this->answeredAt !== null) {
            return; // Latch — first answer wins, keeps the "response time" honest.
        }
        $this->answeredAt = new \DateTimeImmutable();
    }
}
