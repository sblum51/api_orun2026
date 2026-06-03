<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Organization;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Component\HttpFoundation\Response;

final class OrganizationResourceTest extends ApiResourceTestCase
{
    // --- Collection: GET /api/organizations ---------------------------------

    public function testGetCollectionRequiresAuthentication(): void
    {
        static::createClient()->request('GET', '/api/organizations');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testGetCollection(): void
    {
        OrganizationFactory::createMany(3);

        $client = $this->createAuthenticatedClient();
        $response = $client->request('GET', '/api/organizations');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');

        $data = $response->toArray();
        self::assertSame(3, $data['totalItems'] ?? $data['hydra:totalItems'] ?? null);
        self::assertCount(3, $data['member'] ?? $data['hydra:member'] ?? []);
    }

    // --- Collection: POST /api/organizations --------------------------------

    public function testPostCreatesOrganization(): void
    {
        $client = $this->createAuthenticatedClient();
        $response = $client->request('POST', '/api/organizations', [
            'json' => [
                'name' => 'Orun Club',
                'slug' => 'orun-club',
                'description' => 'A test club',
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertJsonContains([
            'name' => 'Orun Club',
            'slug' => 'orun-club',
            'description' => 'A test club',
        ]);

        $data = $response->toArray();
        self::assertMatchesRegularExpression('#^/api/organizations/[0-9a-f-]{36}$#', $data['@id']);

        OrganizationFactory::assert()->count(1);
    }

    public function testPostRequiresAuthentication(): void
    {
        static::createClient()->request('POST', '/api/organizations', [
            'json' => ['name' => 'Nope', 'slug' => 'nope'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        OrganizationFactory::assert()->count(0);
    }

    public function testPostWithInvalidPayloadReturnsValidationError(): void
    {
        $client = $this->createAuthenticatedClient();
        $response = $client->request('POST', '/api/organizations', [
            'json' => ['name' => '', 'slug' => 'Invalid Slug!'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $content = $response->getContent(false);
        self::assertStringContainsString('name', $content);
        self::assertStringContainsString('slug', $content);
        OrganizationFactory::assert()->count(0);
    }

    // --- Item: GET /api/organizations/{id} ----------------------------------

    public function testGetItem(): void
    {
        $organization = OrganizationFactory::createOne(['name' => 'Read Me', 'slug' => 'read-me']);

        $client = $this->createAuthenticatedClient();
        $client->request('GET', $this->iriFor($organization));

        self::assertResponseIsSuccessful();
        self::assertJsonContains([
            'name' => 'Read Me',
            'slug' => 'read-me',
        ]);
    }

    public function testGetUnknownItemReturns404(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/api/organizations/00000000-0000-0000-0000-000000000000');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // --- Item: PATCH /api/organizations/{id} --------------------------------

    public function testPatchUpdatesOrganization(): void
    {
        $owner = UserFactory::createOne();
        $organization = OrganizationFactory::new()
            ->withMember($owner)
            ->create(['name' => 'Old Name', 'slug' => 'patch-me']);

        $client = $this->createAuthenticatedClient($owner);
        $client->request('PATCH', $this->iriFor($organization), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'New Name'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains([
            'name' => 'New Name',
            'slug' => 'patch-me',
        ]);
    }

    public function testPatchRequiresAuthentication(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'guarded']);

        static::createClient()->request('PATCH', $this->iriFor($organization), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Hacked'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testPatchByNonOwnerIsForbidden(): void
    {
        $owner = UserFactory::createOne();
        $attacker = UserFactory::createOne();
        $organization = OrganizationFactory::new()
            ->withMember($owner)
            ->create(['name' => 'Untouched', 'slug' => 'no-touchy']);

        $client = $this->createAuthenticatedClient($attacker);
        $client->request('PATCH', $this->iriFor($organization), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Hijacked'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testPatchByAdminIsAllowed(): void
    {
        $owner = UserFactory::createOne();
        $admin = UserFactory::createOne(['roles' => ['ROLE_ADMIN']]);
        $organization = OrganizationFactory::new()
            ->withMember($owner)
            ->create(['name' => 'Moderated', 'slug' => 'mod-me']);

        $client = $this->createAuthenticatedClient($admin);
        $client->request('PATCH', $this->iriFor($organization), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Admin-edited'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['name' => 'Admin-edited']);
    }

    // --- Item: DELETE /api/organizations/{id} -------------------------------

    public function testDeleteOrganization(): void
    {
        $owner = UserFactory::createOne();
        $organization = OrganizationFactory::new()
            ->withMember($owner)
            ->create(['slug' => 'delete-me']);
        $iri = $this->iriFor($organization);

        $client = $this->createAuthenticatedClient($owner);
        $client->request('DELETE', $iri);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        OrganizationFactory::assert()->count(0);

        $client->request('GET', $iri);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testDeleteRequiresAuthentication(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'safe']);

        static::createClient()->request('DELETE', $this->iriFor($organization));

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        OrganizationFactory::assert()->count(1);
    }

    public function testDeleteByNonOwnerIsForbidden(): void
    {
        $owner = UserFactory::createOne();
        $attacker = UserFactory::createOne();
        $organization = OrganizationFactory::new()
            ->withMember($owner)
            ->create(['slug' => 'protected']);

        $client = $this->createAuthenticatedClient($attacker);
        $client->request('DELETE', $this->iriFor($organization));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        OrganizationFactory::assert()->count(1);
    }

    private function iriFor(Organization $organization): string
    {
        return '/api/organizations/'.$organization->getId()->toRfc4122();
    }
}
