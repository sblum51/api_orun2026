<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ResetPasswordInput
{
    #[Assert\NotBlank]
    public ?string $token = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 8)]
    public ?string $password = null;
}
