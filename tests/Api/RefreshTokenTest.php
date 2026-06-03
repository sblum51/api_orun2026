<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RefreshTokenTest extends ApiResourceTestCase
{
    public function testLoginReturnsAccessAndRefreshTokens(): void
    {
        $this->createUser('alice@orun.dev', 'hunter2-strong');

        $client = static::createClient();
        $response = $client->request('POST', '/auth/login', [
            'json' => ['email' => 'alice@orun.dev', 'password' => 'hunter2-strong'],
        ]);

        self::assertResponseIsSuccessful();
        $data = $response->toArray();

        self::assertArrayHasKey('token', $data);
        self::assertArrayHasKey('refresh_token', $data);
        self::assertIsString($data['token']);
        self::assertIsString($data['refresh_token']);
    }

    public function testRefreshTokenIssuesNewAccessToken(): void
    {
        $this->createUser('bob@orun.dev', 'hunter2-strong');

        $client = static::createClient();
        $login = $client->request('POST', '/auth/login', [
            'json' => ['email' => 'bob@orun.dev', 'password' => 'hunter2-strong'],
        ])->toArray();

        $response = $client->request('POST', '/auth/token/refresh', [
            'json' => ['refresh_token' => $login['refresh_token']],
        ]);

        self::assertResponseIsSuccessful();
        $data = $response->toArray();

        self::assertArrayHasKey('token', $data);
        self::assertArrayHasKey('refresh_token', $data);
        // With single_use: true the refresh token rotates on every use.
        self::assertNotSame($login['refresh_token'], $data['refresh_token']);
    }

    public function testRefreshTokenRejectsUnknownToken(): void
    {
        static::createClient()->request('POST', '/auth/token/refresh', [
            'json' => ['refresh_token' => 'not-a-real-token-just-a-string'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    private function createUser(string $email, string $password): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);
        \assert($em instanceof EntityManagerInterface);

        $user = UserFactory::createOne(['email' => $email]);
        $user->setPassword($hasher->hashPassword($user, $password));
        $em->flush();
    }
}
