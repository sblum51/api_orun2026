<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Event;
use App\Entity\Organization;
use App\Tests\Factory\EventFactory;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Component\HttpFoundation\Response;

final class EventResourceTest extends ApiResourceTestCase
{
    public function testGetCollectionRequiresAuthentication(): void
    {
        static::createClient()->request('GET', '/api/events');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testGetCollection(): void
    {
        EventFactory::createMany(3);

        $client = $this->createAuthenticatedClient();
        $response = $client->request('GET', '/api/events');

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertSame(3, $data['totalItems'] ?? $data['hydra:totalItems'] ?? null);
    }

    public function testPostCreatesEventInOwnedOrganization(): void
    {
        $owner = UserFactory::createOne();
        $organization = OrganizationFactory::new()->withMember($owner)->create();

        $client = $this->createAuthenticatedClient($owner);
        $response = $client->request('POST', '/api/events', [
            'json' => [
                'name' => 'Orun Cup 2026',
                'organization' => $this->iriForOrganization($organization),
                'startDate' => '2026-09-01',
                'endDate' => '2026-09-03',
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertJsonContains(['name' => 'Orun Cup 2026']);

        $data = $response->toArray();
        self::assertMatchesRegularExpression('#^/api/events/[0-9a-f-]{36}$#', $data['@id']);
        EventFactory::assert()->count(1);
    }

    public function testPostRequiresAuthentication(): void
    {
        $organization = OrganizationFactory::createOne();

        static::createClient()->request('POST', '/api/events', [
            'json' => [
                'name' => 'Nope',
                'organization' => $this->iriForOrganization($organization),
                'startDate' => '2026-09-01',
                'endDate' => '2026-09-03',
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        EventFactory::assert()->count(0);
    }

    public function testPostInOtherOrgIsForbidden(): void
    {
        $owner = UserFactory::createOne();
        $attacker = UserFactory::createOne();
        $organization = OrganizationFactory::new()->withMember($owner)->create();

        $client = $this->createAuthenticatedClient($attacker);
        $client->request('POST', '/api/events', [
            'json' => [
                'name' => 'Hijack',
                'organization' => $this->iriForOrganization($organization),
                'startDate' => '2026-09-01',
                'endDate' => '2026-09-03',
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        EventFactory::assert()->count(0);
    }

    public function testGetItem(): void
    {
        $event = EventFactory::createOne(['name' => 'Readable']);

        $client = $this->createAuthenticatedClient();
        $client->request('GET', $this->iriFor($event));

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['name' => 'Readable']);
    }

    public function testGetUnknownReturns404(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/api/events/00000000-0000-0000-0000-000000000000');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testPatchByOrgOwnerSucceeds(): void
    {
        $owner = UserFactory::createOne();
        $organization = OrganizationFactory::new()->withMember($owner)->create();
        $event = EventFactory::createOne(['name' => 'Old', 'organization' => $organization]);

        $client = $this->createAuthenticatedClient($owner);
        $client->request('PATCH', $this->iriFor($event), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Patched'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['name' => 'Patched']);
    }

    public function testPatchByNonOrgOwnerIsForbidden(): void
    {
        $owner = UserFactory::createOne();
        $attacker = UserFactory::createOne();
        $organization = OrganizationFactory::new()->withMember($owner)->create();
        $event = EventFactory::createOne(['name' => 'Untouched', 'organization' => $organization]);

        $client = $this->createAuthenticatedClient($attacker);
        $client->request('PATCH', $this->iriFor($event), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Hijacked'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testPatchByAdminIsAllowed(): void
    {
        $owner = UserFactory::createOne();
        $admin = UserFactory::createOne(['roles' => ['ROLE_ADMIN']]);
        $organization = OrganizationFactory::new()->withMember($owner)->create();
        $event = EventFactory::createOne(['name' => 'Moderated', 'organization' => $organization]);

        $client = $this->createAuthenticatedClient($admin);
        $client->request('PATCH', $this->iriFor($event), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Admin-edited'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['name' => 'Admin-edited']);
    }

    public function testPatchRequiresAuthentication(): void
    {
        $event = EventFactory::createOne();

        static::createClient()->request('PATCH', $this->iriFor($event), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Nope'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testDeleteByOrgOwnerSucceeds(): void
    {
        $owner = UserFactory::createOne();
        $organization = OrganizationFactory::new()->withMember($owner)->create();
        $event = EventFactory::createOne(['organization' => $organization]);

        $client = $this->createAuthenticatedClient($owner);
        $client->request('DELETE', $this->iriFor($event));

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        EventFactory::assert()->count(0);
    }

    public function testDeleteByNonOrgOwnerIsForbidden(): void
    {
        $owner = UserFactory::createOne();
        $attacker = UserFactory::createOne();
        $organization = OrganizationFactory::new()->withMember($owner)->create();
        $event = EventFactory::createOne(['organization' => $organization]);

        $client = $this->createAuthenticatedClient($attacker);
        $client->request('DELETE', $this->iriFor($event));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        EventFactory::assert()->count(1);
    }

    public function testDeleteRequiresAuthentication(): void
    {
        $event = EventFactory::createOne();

        static::createClient()->request('DELETE', $this->iriFor($event));

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        EventFactory::assert()->count(1);
    }

    private function iriFor(Event $event): string
    {
        return '/api/events/'.$event->getId()->toRfc4122();
    }

    private function iriForOrganization(Organization $organization): string
    {
        return '/api/organizations/'.$organization->getId()->toRfc4122();
    }
}
