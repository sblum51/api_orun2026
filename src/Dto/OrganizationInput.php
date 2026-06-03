<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Write model for creating an {@see \App\Entity\Organization}.
 *
 * The slug is derived from the name server-side with collision handling
 * (see {@see \App\State\OrganizationCreateProcessor}). The owner is never
 * accepted from the client either — it is set from the authenticated user.
 */
final class OrganizationInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    #[Groups(['organization:write'])]
    public ?string $name = null;

    #[Groups(['organization:write'])]
    public ?string $description = null;
}
