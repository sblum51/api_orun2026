<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\PasswordResetToken;
use App\Repository\PasswordResetTokenRepository;
use App\Tests\Factory\UserFactory;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;

final class PasswordResetTest extends ApiResourceTestCase
{
    // --- /api/auth/forgot-password -----------------------------------------

    public function testForgotPasswordAlwaysReturns204(): void
    {
        UserFactory::createOne(['email' => 'jane@orun.dev']);

        $client = static::createClient();
        $client->request('POST', '/api/auth/forgot-password', [
            'json' => ['email' => 'jane@orun.dev'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }

    public function testForgotPasswordIsSilentOnUnknownEmail(): void
    {
        // No user with this email. We must NOT leak that fact via status code.
        $client = static::createClient();
        $client->request('POST', '/api/auth/forgot-password', [
            'json' => ['email' => 'ghost@orun.dev'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $tokens = static::getContainer()->get(PasswordResetTokenRepository::class)->findAll();
        self::assertSame([], $tokens);
    }

    public function testForgotPasswordPersistsHashedToken(): void
    {
        UserFactory::createOne(['email' => 'jane@orun.dev']);

        $client = static::createClient();
        $client->request('POST', '/api/auth/forgot-password', [
            'json' => ['email' => 'jane@orun.dev'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        /** @var list<PasswordResetToken> $tokens */
        $tokens = static::getContainer()->get(PasswordResetTokenRepository::class)->findAll();
        self::assertCount(1, $tokens);
        self::assertSame(64, \strlen($tokens[0]->getTokenHash()));
        self::assertGreaterThan(new \DateTimeImmutable(), $tokens[0]->getExpiresAt());
    }

    public function testForgotPasswordSendsEmail(): void
    {
        UserFactory::createOne(['email' => 'jane@orun.dev']);

        $client = static::createClient();
        $client->request('POST', '/api/auth/forgot-password', [
            'json' => ['email' => 'jane@orun.dev'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertEmailCount(1);

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('Réinitialisation de votre mot de passe', $email->getSubject());
        $body = $email->getTextBody();
        self::assertIsString($body);
        self::assertStringContainsString('/reset-password?token=', $body);
    }

    public function testForgotPasswordRejectsInvalidEmail(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/auth/forgot-password', [
            'json' => ['email' => 'not-an-email'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    // --- /api/auth/reset-password ------------------------------------------

    public function testResetPasswordWithValidTokenUpdatesPassword(): void
    {
        $user = UserFactory::createOne([
            'email' => 'jane@orun.dev',
            'password' => 'old-password-strong',
        ]);

        $client = static::createClient();
        $client->request('POST', '/api/auth/forgot-password', [
            'json' => ['email' => 'jane@orun.dev'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $rawToken = $this->extractTokenFromEmail();

        $client->request('POST', '/api/auth/reset-password', [
            'json' => [
                'token' => $rawToken,
                'password' => 'brand-new-strong-password',
            ],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        // Old password no longer works; new one does.
        $client->request('POST', '/auth/login', [
            'json' => ['email' => 'jane@orun.dev', 'password' => 'old-password-strong'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $client->request('POST', '/auth/login', [
            'json' => ['email' => 'jane@orun.dev', 'password' => 'brand-new-strong-password'],
        ]);
        self::assertResponseIsSuccessful();

        unset($user);
    }

    public function testResetPasswordWithUnknownTokenIs400(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/auth/reset-password', [
            'json' => [
                'token' => 'totally-bogus-token',
                'password' => 'brand-new-strong-password',
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testResetPasswordTokenIsOneShot(): void
    {
        UserFactory::createOne(['email' => 'jane@orun.dev']);

        $client = static::createClient();
        $client->request('POST', '/api/auth/forgot-password', [
            'json' => ['email' => 'jane@orun.dev'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $rawToken = $this->extractTokenFromEmail();

        $client->request('POST', '/api/auth/reset-password', [
            'json' => ['token' => $rawToken, 'password' => 'first-rotation-pw'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $client->request('POST', '/api/auth/reset-password', [
            'json' => ['token' => $rawToken, 'password' => 'second-attempt-pw'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testResetPasswordRejectsShortPassword(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/auth/reset-password', [
            'json' => ['token' => 'whatever', 'password' => 'short'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function extractTokenFromEmail(): string
    {
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        $body = $email->getTextBody();
        self::assertIsString($body);
        $matched = preg_match('/token=([0-9a-f]+)/', $body, $matches);
        self::assertSame(1, $matched);

        return $matches[1];
    }
}
