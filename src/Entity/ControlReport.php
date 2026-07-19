<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Enum\ControlReportReason;
use App\Enum\ControlReportStatus;
use App\Repository\ControlReportRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A runner-submitted signal that a control on the course is not in a
 * usable state — missing sign, damaged QR sticker, dead beacon…
 *
 * Life-cycle:
 *   pending    → newly submitted, waiting for a human
 *   acknowledged → a manager saw it and takes action
 *   resolved   → the physical control was fixed / replaced
 *   dismissed  → false report, ignored
 *
 * Not exposed as an ApiResource: two custom controllers (submit + list
 * + acknowledge) fully cover the surface with tight auth. Photo is
 * uploaded via multipart to the submit endpoint; the resulting URL is
 * stored in `photoUrl`.
 */
#[ORM\Entity(repositoryClass: ControlReportRepository::class)]
#[ORM\Table(name: 'control_reports')]
#[ORM\HasLifecycleCallbacks]
class ControlReport
{
    use IdentifiableTrait;
    use TimestampableTrait;

    #[ORM\ManyToOne(targetEntity: Activity::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Activity $activity;

    #[ORM\ManyToOne(targetEntity: Control::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Control $control;

    #[ORM\Column(type: 'string', length: 20, enumType: ControlReportReason::class)]
    private ControlReportReason $reason;

    #[ORM\Column(type: 'string', length: 20, enumType: ControlReportStatus::class)]
    private ControlReportStatus $status = ControlReportStatus::Pending;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $photoUrl = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $acknowledgedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'acknowledged_by_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $acknowledgedBy = null;

    public function __construct(
        Activity $activity,
        Control $control,
        ControlReportReason $reason,
        ?string $comment = null,
    ) {
        $this->initializeUuid();
        $this->activity = $activity;
        $this->control = $control;
        $this->reason = $reason;
        $this->comment = $comment;
    }

    public function getActivity(): Activity { return $this->activity; }
    public function getControl(): Control { return $this->control; }
    public function getReason(): ControlReportReason { return $this->reason; }
    public function getStatus(): ControlReportStatus { return $this->status; }
    public function getComment(): ?string { return $this->comment; }
    public function getPhotoUrl(): ?string { return $this->photoUrl; }
    public function getAcknowledgedAt(): ?\DateTimeImmutable { return $this->acknowledgedAt; }
    public function getAcknowledgedBy(): ?User { return $this->acknowledgedBy; }

    public function setPhotoUrl(?string $url): void
    {
        $this->photoUrl = $url;
    }

    /**
     * Transition helper called by the manager PATCH endpoint. Latches
     * `acknowledgedAt`/`acknowledgedBy` on the first transition out of
     * pending, but doesn't rewrite them on later state changes so the
     * audit shows "first person who saw it".
     */
    public function transition(ControlReportStatus $to, User $actor): void
    {
        $this->status = $to;
        if ($this->acknowledgedAt === null && $to !== ControlReportStatus::Pending) {
            $this->acknowledgedAt = new \DateTimeImmutable();
            $this->acknowledgedBy = $actor;
        }
    }
}
