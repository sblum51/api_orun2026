<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ForgotPasswordInput
{
    #[Assert\NotBlank]
    #[Assert\Email]
    public ?string $email = null;
}
