<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiableTrait;
use App\Enum\ControlValidationMethod;
use App\Repository\PunchRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PunchRepository::class)]
#[ORM\Table(name: 'punches')]
class Punch
{
    use IdentifiableTrait;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $punchedAt;

    #[ORM\Column(type: 'string', length: 30, enumType: ControlValidationMethod::class)]
    private ControlValidationMethod $methodUsed;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $longitude = null;

    #[ORM\ManyToOne(targetEntity: Activity::class, inversedBy: 'punches')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Activity $activity;

    #[ORM\ManyToOne(targetEntity: Control::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Control $control;

    public function __construct(Activity $activity, Control $control, \DateTimeImmutable $punchedAt, ControlValidationMethod $methodUsed)
    {
        $this->initializeUuid();
        $this->activity = $activity;
        $this->control = $control;
        $this->punchedAt = $punchedAt;
        $this->methodUsed = $methodUsed;
    }

    public function getPunchedAt(): \DateTimeImmutable
    {
        return $this->punchedAt;
    }

    public function getMethodUsed(): ControlValidationMethod
    {
        return $this->methodUsed;
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

    public function getActivity(): Activity
    {
        return $this->activity;
    }

    public function getControl(): Control
    {
        return $this->control;
    }
}
