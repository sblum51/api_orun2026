<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Dto\OrganizationInput;
use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\OrganizationRepository;
use App\State\OrganizationCreateProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: OrganizationRepository::class)]
#[ORM\Table(name: 'organizations')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(
            input: OrganizationInput::class,
            processor: OrganizationCreateProcessor::class,
            security: "is_granted('ROLE_USER')",
        ),
        new Patch(
            security: "is_granted('manage', object)",
        ),
        new Delete(
            security: "is_granted('manage', object)",
        ),
    ],
    normalizationContext: ['groups' => ['organization:read']],
    denormalizationContext: ['groups' => ['organization:write']],
)]
class Organization
{
    use IdentifiableTrait;
    use TimestampableTrait;

    #[ORM\Column(type: 'string', length: 150)]
    #[Assert\NotBlank]
    #[Groups(['organization:read', 'organization:write'])]
    private string $name;

    #[ORM\Column(type: 'string', length: 160, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^[a-z0-9\-]+$/')]
    #[Groups(['organization:read', 'organization:write'])]
    private string $slug;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['organization:read', 'organization:write'])]
    private ?string $description = null;

    /**
     * @var Collection<int, OrganizationMember>
     */
    #[ORM\OneToMany(mappedBy: 'organization', targetEntity: OrganizationMember::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $members;

    public function __construct(string $name, string $slug)
    {
        $this->initializeUuid();
        $this->name = $name;
        $this->slug = $slug;
        $this->members = new ArrayCollection();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    /**
     * @return Collection<int, OrganizationMember>
     */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function addMember(User $user): OrganizationMember
    {
        foreach ($this->members as $existing) {
            if ($existing->getUser() === $user) {
                return $existing;
            }
        }

        $member = new OrganizationMember($this, $user);
        $this->members->add($member);

        return $member;
    }
}
