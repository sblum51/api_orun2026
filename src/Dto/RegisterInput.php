<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload accepted by POST /auth/register.
 *
 * Validated via Symfony Validator before reaching the processor.
 */
final class RegisterInput
{
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    #[Groups(['user:write'])]
    public ?string $email = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 8, max: 255)]
    #[Groups(['user:write'])]
    public ?string $password = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[Groups(['user:write'])]
    public ?string $firstName = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[Groups(['user:write'])]
    public ?string $lastName = null;
}
