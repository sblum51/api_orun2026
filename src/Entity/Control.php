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
use App\Enum\ControlType;
use App\Enum\ControlValidationMethod;
use App\Repository\ControlRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ControlRepository::class)]
#[ORM\Table(name: 'controls')]
// PostgreSQL treats NULLs as distinct in a UNIQUE constraint (NULL != NULL)
// so this naturally allows several Start/Finish rows per event (their
// `code` is NULL) while still rejecting duplicate numeric codes on
// type='control' rows. Cleanest: keep the regular constraint.
#[ORM\UniqueConstraint(name: 'controls_event_code_uniq', columns: ['event_id', 'code'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(security: "is_granted('view', object)"),
        new Post(
            security: "is_granted('ROLE_USER')",
            securityPostDenormalize: "is_granted('manage', object.getEvent())",
            securityPostDenormalizeMessage: 'You can only create controls in an event you manage.',
        ),
        new Patch(security: "is_granted('manage', object.getEvent())"),
        new Delete(security: "is_granted('manage', object.getEvent())"),
    ],
    normalizationContext: ['groups' => ['control:read']],
    denormalizationContext: ['groups' => ['control:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['event' => 'exact'])]
class Control
{
    use IdentifiableTrait;
    use TimestampableTrait;

    /**
     * Role of this control inside its parent event. Default `Control` is the
     * numbered orienteering station; `Start` / `Finish` skip the numeric
     * code requirement and use the optional `label` for display (S1, F1…).
     */
    #[ORM\Column(type: 'string', length: 20, enumType: ControlType::class, options: ['default' => 'control'])]
    #[Groups(['control:read', 'control:write'])]
    private ControlType $type = ControlType::Control;

    /**
     * Numbered station code (31-400 per IOF). Mandatory for type=Control,
     * left null for Start/Finish — enforced application-side in the
     * lifecycle hook below so the type→code rule lives in one spot.
     */
    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Assert\Range(min: 31, max: 400)]
    #[Groups(['control:read', 'control:write'])]
    private ?int $code = null;

    /**
     * Free-form label shown in the UI when there's no numeric code —
     * typically "S1", "F1", "Départ Vert"… Used by both the manager list
     * and the mobile run screen for Start/Finish steps.
     */
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    #[Groups(['control:read', 'control:write'])]
    private ?string $label = null;

    /**
     * Validation methods enabled on this control. A runner only needs to
     * succeed at ONE of them to validate the punch — useful when the same
     * stake carries a QR sticker AND an NFC tag, with GPS as a fallback.
     *
     * Stored as a JSON array of {@see ControlValidationMethod} backing values.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    #[Groups(['control:read', 'control:write'])]
    #[Assert\Count(min: 1, minMessage: 'At least one validation method is required.')]
    #[Assert\All([
        new Assert\Choice(callback: [ControlValidationMethod::class, 'values']),
    ])]
    private array $validationMethods = [];

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
    #[Groups(['control:read', 'control:write'])]
    private array $payload = [];

    #[ORM\Column(type: 'float', nullable: true)]
    #[Assert\Range(min: -90, max: 90)]
    #[Groups(['control:read', 'control:write'])]
    private ?float $latitude = null;

    #[ORM\Column(type: 'float', nullable: true)]
    #[Assert\Range(min: -180, max: 180)]
    #[Groups(['control:read', 'control:write'])]
    private ?float $longitude = null;

    #[ORM\Column(type: 'string', length: 200, nullable: true)]
    #[Groups(['control:read', 'control:write'])]
    private ?string $note = null;

    #[ORM\ManyToOne(targetEntity: Event::class, inversedBy: 'controls')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    #[Groups(['control:read', 'control:write'])]
    private ?Event $event = null;

    /**
     * Physical tags from the operator's library stamped on this control —
     * an NFC sticker, a printed QR, an iBeacon. Multiple tags can sit on the
     * same stake (e.g. NFC + QR backups). Wiped from the pivot when either
     * side is deleted.
     *
     * @var Collection<int, Tag>
     */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'control_tags')]
    #[ORM\JoinColumn(name: 'control_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'tag_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[Groups(['control:read', 'control:write'])]
    private Collection $tags;

    /**
     * @param list<ControlValidationMethod> $validationMethods
     */
    public function __construct(?Event $event = null, ?int $code = null, array $validationMethods = [])
    {
        $this->initializeUuid();
        $this->tags = new ArrayCollection();
        if (null !== $event) {
            $this->event = $event;
        }
        if (null !== $code) {
            $this->code = $code;
        }
        if ([] !== $validationMethods) {
            $this->setValidationMethods($validationMethods);
        }
    }

    public function getType(): ControlType
    {
        return $this->type;
    }

    public function setType(ControlType $type): void
    {
        $this->type = $type;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): void
    {
        $this->label = $label;
    }

    public function getCode(): ?int
    {
        return $this->code;
    }

    public function setCode(?int $code): void
    {
        $this->code = $code;
    }

    /**
     * Cross-field invariant : the numeric code is mandatory for `Control`
     * type and must be absent for Start/Finish. Enforced on persist + update
     * so the same rule applies to API Platform POSTs, manager edits, fixture
     * builders and bulk imports without each path having to remember.
     */
    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function validateTypeCodeInvariant(): void
    {
        if (ControlType::Control === $this->type) {
            if (null === $this->code) {
                throw new \DomainException('A "control" requires a numeric code (31-400).');
            }
        } else {
            // Start / Finish never carry a code — quietly drop one if the
            // caller passed something.
            $this->code = null;
        }
    }

    /**
     * @return list<string>
     */
    public function getValidationMethods(): array
    {
        return $this->validationMethods;
    }

    /**
     * @param list<ControlValidationMethod|string> $methods
     */
    public function setValidationMethods(array $methods): void
    {
        $normalized = [];
        foreach ($methods as $method) {
            $value = $method instanceof ControlValidationMethod ? $method->value : (string) $method;
            if (!\in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }
        $this->validationMethods = array_values($normalized);
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

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function setEvent(Event $event): void
    {
        $this->event = $event;
    }

    /**
     * @return Collection<int, Tag>
     */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(Tag $tag): void
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }
    }

    public function removeTag(Tag $tag): void
    {
        $this->tags->removeElement($tag);
    }
}
