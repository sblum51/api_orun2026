<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\ResetPasswordInput;
use App\Repository\PasswordResetTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Consumes a one-shot password-reset token and updates the user's password.
 *
 * @implements ProcessorInterface<ResetPasswordInput, null>
 */
final readonly class ResetPasswordProcessor implements ProcessorInterface
{
    public function __construct(
        private PasswordResetTokenRepository $tokenRepository,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        \assert($data instanceof ResetPasswordInput);

        $now = new \DateTimeImmutable();
        $tokenHash = hash('sha256', (string) $data->token);
        $resetToken = $this->tokenRepository->findActiveByHash($tokenHash, $now);
        if (null === $resetToken) {
            throw new BadRequestHttpException('Invalid or expired token.');
        }

        $user = $resetToken->getUser();
        $user->setPassword($this->passwordHasher->hashPassword($user, (string) $data->password));
        $resetToken->markUsed($now);

        $this->entityManager->flush();

        return null;
    }
}
