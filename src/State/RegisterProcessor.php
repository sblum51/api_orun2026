<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\RegisterInput;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): User
    {
        \assert($data instanceof RegisterInput);

        $user = new User(
            (string) $data->email,
            (string) $data->firstName,
            (string) $data->lastName,
        );
        $user->setPassword(
            $this->passwordHasher->hashPassword($user, (string) $data->password),
        );

        return $this->persistProcessor->process($user, $operation, $uriVariables, $context);
    }
}
