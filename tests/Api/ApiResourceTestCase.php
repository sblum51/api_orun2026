<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\User;
use App\Tests\Factory\UserFactory;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Base class for API Platform functional tests.
 *
 * The whole API sits behind a stateless JWT firewall, so every test needs a
 * bearer token. {@see createAuthenticatedClient()} persists a user and mints a
 * real JWT for it, then preconfigures the client to send it on every request.
 */
abstract class ApiResourceTestCase extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    // Opt into the API Platform 5 behaviour now to silence the 4.x deprecation.
    protected static ?bool $alwaysBootKernel = true;

    protected function createAuthenticatedClient(?User $user = null): Client
    {
        $client = static::createClient();

        $user ??= UserFactory::createOne();
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        $client->setDefaultOptions(['auth_bearer' => $token]);

        return $client;
    }
}
