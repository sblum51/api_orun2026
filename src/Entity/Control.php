<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Enum\ControlValidationMethod;
use App\Repository\ControlRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ControlRepository::class)]
#[ORM\Table(name: 'controls')]
#[ORM\UniqueConstraint(name: 'controls_event_code_uniq', columns: ['event_id', 'code'])]
#[ORM\HasLifecycleCallbacks]
class Control
{
    use IdentifiableTrait;
    use TimestampableTrait;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Range(min: 31, max: 400)]
    private int $code;

    #[ORM\Column(type: 'string', length: 30, enumType: ControlValidationMethod::class)]
    private ControlValidationMethod $validationMethod;

    /**
     * Method-specific payload — examples:
     *  - QrCode : { "url": "https://o-club.net/<event>/<id>" }
     *  - Nfc    : { "uid": "04:AA:BB:CC:DD:EE:FF" }
     *  - IBeacon: { "uuid": "...", "major": 1, "minor": 2 }
     *  - Uwb    : { "id": "..." }
     *  - Gps    : { "lat": 48.8, "lng": 2.35, "radiusM": 25 }.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $payload = [];

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $longitude = null;

    #[ORM\Column(type: 'string', length: 200, nullable: true)]
    private ?string $note = null;

    #[ORM\ManyToOne(targetEntity: Event::class, inversedBy: 'controls')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Event $event;

    public function __construct(Event $event, int $code, ControlValidationMethod $validationMethod)
    {
        $this->initializeUuid();
        $this->event = $event;
        $this->code = $code;
        $this->validationMethod = $validationMethod;
    }

    public function getCode(): int
    {
        return $this->code;
    }

    public function setCode(int $code): void
    {
        $this->code = $code;
    }

    public function getValidationMethod(): ControlValidationMethod
    {
        return $this->validationMethod;
    }

    public function setValidationMethod(ControlValidationMethod $method): void
    {
        $this->validationMethod = $method;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function setPayload(array $payload): void
    {
        $this->payload = $payload;
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

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): void
    {
        $this->note = $note;
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function setEvent(Event $event): void
    {
        $this->event = $event;
    }
}
