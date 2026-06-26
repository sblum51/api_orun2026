<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\ForgotPasswordInput;
use App\Entity\PasswordResetToken;
use App\Repository\UserRepository;
use App\Service\PasswordResetEmailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Issues a password-reset token for the user identified by the email in the
 * payload, and emails the link. The response is always 204 No Content so an
 * attacker cannot probe whether an email is registered.
 *
 * Rate-limited per source IP via the `password_reset` limiter.
 *
 * @implements ProcessorInterface<ForgotPasswordInput, null>
 */
final readonly class ForgotPasswordProcessor implements ProcessorInterface
{
    private const TOKEN_TTL_SECONDS = 3600;

    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $entityManager,
        private PasswordResetEmailer $emailer,
        #[Autowire(service: 'limiter.password_reset')]
        private RateLimiterFactoryInterface $passwordResetLimiter,
        private RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        \assert($data instanceof ForgotPasswordInput);

        $request = $this->requestStack->getCurrentRequest();
        $clientIp = $request?->getClientIp() ?? 'unknown';
        $limiter = $this->passwordResetLimiter->create($clientIp);
        if (false === $limiter->consume(1)->isAccepted()) {
            throw new TooManyRequestsHttpException(message: 'Too many password reset requests. Try again later.');
        }

        $user = $this->userRepository->findOneBy(['email' => $data->email]);
        if (null !== $user) {
            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $expiresAt = (new \DateTimeImmutable())->modify('+'.self::TOKEN_TTL_SECONDS.' seconds');

            $resetToken = new PasswordResetToken($user, $tokenHash, $expiresAt);
            $this->entityManager->persist($resetToken);
            $this->entityManager->flush();

            $this->emailer->send($user, $rawToken);
        }

        return null;
    }
}
