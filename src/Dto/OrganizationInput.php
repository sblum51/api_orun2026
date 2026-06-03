<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Write model for creating an {@see \App\Entity\Organization}.
 *
 * The owner is never accepted from the client — it is derived from the
 * authenticated user in {@see \App\State\OrganizationCreateProcessor}.
 */
final class OrganizationInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    #[Groups(['organization:write'])]
    public ?string $name = null;

    #[Assert\NotBlank]
    #[Assert\Regex('/^[a-z0-9\-]+$/')]
    #[Assert\Length(max: 160)]
    #[Groups(['organization:write'])]
    public ?string $slug = null;

    #[Groups(['organization:write'])]
    public ?string $description = null;
}
