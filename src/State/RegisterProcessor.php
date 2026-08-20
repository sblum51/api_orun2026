<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\RegisterInput;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Turns a {@see RegisterInput} into a persisted {@see User} with a hashed
 * password.
 *
 * @implements ProcessorInterface<RegisterInput, User>
 */
final readonly class RegisterProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<User, User> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private UserPasswordHasherInterface $passwordHasher,
        private UserRepository $users,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): User
    {
        \assert($data instanceof RegisterInput);

        $email = strtolower(trim((string) $data->email));
        // Pre-flight uniqueness check so a duplicate returns a clean 409
        // instead of surfacing a Doctrine UniqueConstraintViolation as a
        // 500. The DB unique index still guards against races.
        if ($this->users->findOneBy(['email' => $email]) !== null) {
            throw new ConflictHttpException('Un compte existe déjà avec cet email.');
        }

        $user = new User(
            $email,
            (string) $data->firstName,
            (string) $data->lastName,
        );
        $user->setPassword(
            $this->passwordHasher->hashPassword($user, (string) $data->password),
        );

        try {
            return $this->persistProcessor->process($user, $operation, $uriVariables, $context);
        } catch (UniqueConstraintViolationException) {
            // Race: another request inserted the same email between our
            // pre-flight check and this commit. Same user-facing story.
            throw new ConflictHttpException('Un compte existe déjà avec cet email.');
        }
    }
}
