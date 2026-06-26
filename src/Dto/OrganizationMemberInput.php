<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Write model for adding a user to an organization by email.
 */
final class OrganizationMemberInput
{
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Groups(['organization_member:write'])]
    public ?string $email = null;

    /**
     * IRI of the target organization (e.g. /api/organizations/{id}).
     */
    #[Assert\NotBlank]
    #[Groups(['organization_member:write'])]
    public ?string $organization = null;
}
