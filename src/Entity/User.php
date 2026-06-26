<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Dto\ForgotPasswordInput;
use App\Dto\RegisterInput;
use App\Dto\ResetPasswordInput;
use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\UserRepository;
use App\State\ForgotPasswordProcessor;
use App\State\RegisterProcessor;
use App\State\ResetPasswordProcessor;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/auth/register',
            input: RegisterInput::class,
            processor: RegisterProcessor::class,
            normalizationContext: ['groups' => ['user:read']],
        ),
        new Post(
            uriTemplate: '/auth/forgot-password',
            status: Response::HTTP_NO_CONTENT,
            input: ForgotPasswordInput::class,
            output: false,
            processor: ForgotPasswordProcessor::class,
            read: false,
            write: true,
        ),
        new Post(
            uriTemplate: '/auth/reset-password',
            status: Response::HTTP_NO_CONTENT,
            input: ResetPasswordInput::class,
            output: false,
            processor: ResetPasswordProcessor::class,
            read: false,
            write: true,
        ),
    ],
)]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use IdentifiableTrait;
    use TimestampableTrait;

    #[ORM\Column(type: 'string', length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Groups(['user:read', 'organization_member:read'])]
    private string $email;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(type: 'string')]
    private string $password;

    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank]
    #[Groups(['user:read', 'organization_member:read'])]
    private string $firstName;

    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank]
    #[Groups(['user:read', 'organization_member:read'])]
    private string $lastName;

    public function __construct(string $email, string $firstName, string $lastName)
    {
        $this->initializeUuid();
        $this->email = $email;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): void
    {
        $this->firstName = $firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): void
    {
        $this->lastName = $lastName;
    }

    public function getUserIdentifier(): string
    {
        \assert('' !== $this->email);

        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): void
    {
        $this->roles = $roles;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }

    public function eraseCredentials(): void
    {
    }
}
