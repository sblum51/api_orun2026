<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\ExistsFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Enum\TagType;
use App\Repository\TagRepository;
use App\State\TagPersistProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A reusable validation tag (NFC sticker, QR Code, iBeacon). Lives in either:
 *
 *  - an organization's shared library (every org member can use it), OR
 *  - a manager's personal library (organization = null).
 *
 * Once stamped onto a Control, the tag's payload feeds the mobile app for
 * runtime validation — but the Tag itself stays an inventory item so the
 * operator can re-use the same NFC roll across events / seasons.
 */
#[ORM\Entity(repositoryClass: TagRepository::class)]
#[ORM\Table(name: 'tags')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('manage', object)"),
        new Post(
            security: "is_granted('ROLE_USER')",
            processor: TagPersistProcessor::class,
        ),
        new Patch(security: "is_granted('manage', object)"),
        new Delete(security: "is_granted('manage', object)"),
    ],
    normalizationContext: ['groups' => ['tag:read']],
    denormalizationContext: ['groups' => ['tag:write']],
)]
/*
 * Subresource: GET /api/organizations/{organizationId}/tags — the library
 * tied to a specific organization. Filtered server-side so the manager UI
 * doesn't have to paginate through every tag in the database.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/organizations/{organizationId}/tags',
            uriVariables: [
                'organizationId' => new Link(fromClass: Organization::class, toProperty: 'organization'),
            ],
            security: "is_granted('ROLE_USER')",
            normalizationContext: ['groups' => ['tag:read']],
        ),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['organization' => 'exact', 'type' => 'exact', 'creator' => 'exact'])]
// Lets the personal-library view query `?exists[organization]=false` to
// surface only tags filed under no organization.
#[ApiFilter(ExistsFilter::class, properties: ['organization'])]
class Tag
{
    use IdentifiableTrait;
    use TimestampableTrait;

    // `control:read` is added on type/name/payload (NOT on organization/creator)
    // so a Control's serialized response embeds the tag info the mobile app
    // needs to match scans against, without leaking who owns the tag stock.
    #[ORM\Column(type: 'string', length: 30, enumType: TagType::class)]
    #[Groups(['tag:read', 'tag:write', 'control:read'])]
    private TagType $type;

    #[ORM\Column(type: 'string', length: 200)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    #[Groups(['tag:read', 'tag:write', 'control:read'])]
    private string $name;

    /**
     * Type-specific payload:
     *  - QrCode : { "code": "ABC123" } or { "url": "https://o-club.net/..." }
     *  - Nfc    : { "uid": "04:AA:BB:CC:DD:EE:FF" }
     *  - IBeacon: { "uuid": "f7826da6-4fa2-4e98-8024-bc5b71e0893e", "major": 1, "minor": 2 }
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    #[Groups(['tag:read', 'tag:write', 'control:read'])]
    private array $payload = [];

    /**
     * Optional shared owner. When null, the tag lives in its creator's
     * personal library and only the creator can use it.
     */
    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[Groups(['tag:read', 'tag:write'])]
    private ?Organization $organization = null;

    /**
     * Always set from the authenticated user at POST time (never accepted
     * from the client). Used by the TagVoter so a personal tag can be
     * managed by the user who created it.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Groups(['tag:read'])]
    private ?User $creator = null;

    public function __construct(?TagType $type = null, ?string $name = null)
    {
        $this->initializeUuid();
        if (null !== $type) {
            $this->type = $type;
        }
        if (null !== $name) {
            $this->name = $name;
        }
    }

    public function getType(): TagType
    {
        return $this->type;
    }

    public function setType(TagType $type): void
    {
        $this->type = $type;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
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

    public function getOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function setOrganization(?Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function getCreator(): ?User
    {
        return $this->creator;
    }

    public function setCreator(User $creator): void
    {
        $this->creator = $creator;
    }
}
