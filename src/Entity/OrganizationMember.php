<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\OrganizationMemberRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrganizationMemberRepository::class)]
#[ORM\Table(name: 'organization_members')]
#[ORM\UniqueConstraint(name: 'org_member_uniq', columns: ['organization_id', 'user_id'])]
#[ORM\HasLifecycleCallbacks]
class OrganizationMember
{
    use IdentifiableTrait;
    use TimestampableTrait;

    #[ORM\ManyToOne(targetEntity: Organization::class, inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
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
