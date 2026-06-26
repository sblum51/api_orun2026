<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Dto\OrganizationMemberInput;
use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\OrganizationMemberRepository;
use App\State\OrganizationMemberCollectionProvider;
use App\State\OrganizationMemberCreateProcessor;
use App\State\OrganizationMemberDeleteProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: OrganizationMemberRepository::class)]
#[ORM\Table(name: 'organization_members')]
#[ORM\UniqueConstraint(name: 'org_member_uniq', columns: ['organization_id', 'user_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'OrganizationMember',
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
            provider: OrganizationMemberCollectionProvider::class,
        ),
        new Post(
            input: OrganizationMemberInput::class,
            processor: OrganizationMemberCreateProcessor::class,
            security: "is_granted('ROLE_USER')",
        ),
        new Delete(
            security: "is_granted('manage', object.getOrganization())",
            processor: OrganizationMemberDeleteProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['organization_member:read']],
    denormalizationContext: ['groups' => ['organization_member:write']],
)]
class OrganizationMember
{
    use IdentifiableTrait;
    use TimestampableTrait;

    #[ORM\ManyToOne(targetEntity: Organization::class, inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['organization_member:read'])]
    private Organization $organization;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['organization_member:read'])]
    private User $user;

    public function __construct(Organization $organization, User $user)
    {
        $this->initializeUuid();
        $this->organization = $organization;
        $this->user = $user;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getUser(): User
    {
        return $this->user;
    }
}
