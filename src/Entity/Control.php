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
     * Role of this control inside its parent event. Default `Control` is
     * the numbered orienteering station; `Start` / `Finish` use a textual
     * code like "S1" / "F1" — same field, different format.
     */
    #[ORM\Column(type: 'string', length: 20, enumType: ControlType::class, options: ['default' => 'control'])]
    #[Groups(['control:read', 'control:write'])]
    private ControlType $type = ControlType::Control;

    /**
     * Unique identifier of the control within its event. Always required.
     * Format depends on the type:
     *  - `Control` : numeric string "31" through "400" (IOF orienteering codes).
     *  - `Start`   : free-form, conventionally "S1", "S2", …
     *  - `Finish`  : free-form, conventionally "F1", "F2", …
     *
     * Type-specific validation runs in {@see validateTypeCodeInvariant()}
     * so every entry point (manager, raw API, fixtures, KMZ import) shares
     * the same rule.
     */
    #[ORM\Column(type: 'string', length: 10)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 10)]
    #[Groups(['control:read', 'control:write'])]
    private string $code = '';

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
     * @param int|string|null              $code Numeric (31-400) for type=Control,
     *                                           or any short string ("S1", "F1")
     *                                           for Start/Finish. Coerced to string
     *                                           via {@see setCode()}.
     * @param list<ControlValidationMethod> $validationMethods
     */
    public function __construct(?Event $event = null, int|string|null $code = null, array $validationMethods = [])
    {
        $this->initializeUuid();
        $this->tags = new ArrayCollection();
        if (null !== $event) {
            $this->event = $event;
        }
        if (null !== $code) {
            $this->setCode((string) $code);
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

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): void
    {
        $this->code = trim($code);
    }

    /**
     * Cross-field invariant : `Control` codes must parse as 31-400, while
     * `Start` / `Finish` accept any non-blank short string (typically
     * S1/F1). Enforced on persist + update so every entry point shares
     * the rule.
     */
    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function validateTypeCodeInvariant(): void
    {
        if ('' === $this->code) {
            throw new \DomainException('A control code is required.');
        }
        if (ControlType::Control === $this->type) {
            if (!ctype_digit($this->code)) {
                throw new \DomainException('A "control" code must be numeric (31-400). Got "'.$this->code.'".');
            }
            $n = (int) $this->code;
            if ($n < 31 || $n > 400) {
                throw new \DomainException('A "control" code must be 31-400. Got "'.$this->code.'".');
            }
        }
        // Start / Finish accept any non-blank short string — already
        // covered by the `'' === $this->code` check above, no extra work.
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
