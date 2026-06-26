<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Event;
use App\Entity\Organization;
use App\Enum\Visibility;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\EventFactory;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Component\HttpFoundation\Response;

final class EventResourceTest extends ApiResourceTestCase
{
    /**
     * Anonymous read access is intentional — the mobile app browses events
     * without a login. The {@see \App\Doctrine\VisibilityExtension} hides
     * Private events server-side and the `event:public` group keeps the
     * response slim (no creator, no description, no internal flags).
     */
    public function testGetCollectionAllowsAnonymous(): void
    {
        EventFactory::createMany(3);

        $response = static::createClient()->request('GET', '/api/events');

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertSame(3, $data['totalItems'] ?? $data['hydra:totalItems'] ?? null);
    }

    public function testGetCollectionHidesPrivateEventsFromAnonymous(): void
    {
        EventFactory::createMany(2, ['visibility' => Visibility::Public]);
        EventFactory::createMany(3, ['visibility' => Visibility::Private]);

        $response = static::createClient()->request('GET', '/api/events');

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertSame(2, $data['totalItems'] ?? $data['hydra:totalItems'] ?? null);
        $items = $data['member'] ?? $data['hydra:member'] ?? [];
        self::assertCount(2, $items);
    }

    public function testGetCollectionReturnsSlimShapeOnly(): void
    {
        $event = EventFactory::createOne([
            'name' => 'Public Run',
            'location' => 'Forêt de Verzy',
            'visibility' => Visibility::Public,
            'coverImageUrl' => 'https://example.com/cover.jpg',
        ]);
        CourseFactory::createMany(3, ['event' => $event]);

        $response = static::createClient()->request('GET', '/api/events');

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        $items = $data['member'] ?? $data['hydra:member'] ?? [];
        self::assertCount(1, $items);

        $item = $items[0];
        // What MUST be there.
        self::assertSame('Public Run', $item['name']);
        self::assertSame('Forêt de Verzy', $item['location']);
        self::assertArrayHasKey('slug', $item);
        self::assertArrayHasKey('type', $item);
        self::assertArrayHasKey('@id', $item);
        self::assertSame(3, $item['coursesCount']);
        // coverImageUrl is part of the public projection.
        self::assertSame('https://example.com/cover.jpg', $item['coverImageUrl']);

        // What MUST NOT leak in the public projection — internal flags,
        // ownership, dates, geometry. If any of these appear, widen this
        // test rather than the group; the slim shape is the contract.
        $forbidden = [
            'description', 'startDate', 'endDate', 'latitude', 'longitude',
            'showMap', 'published', 'visibility', 'organization', 'creator',
            'defaultValidationMethods', 'createdAt', 'updatedAt',
        ];
        foreach ($forbidden as $key) {
            self::assertArrayNotHasKey(
                $key,
                $item,
                "Field `$key` must not leak in the anonymous /api/events listing.",
            );
        }
    }

    public function testGetCollectionIsPaginated(): void
    {
        EventFactory::createMany(45);

        $response = static::createClient()->request('GET', '/api/events');

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        // API Platform defaults to 30 items/page — totalItems reflects the
        // whole set but member/array is limited to the page size.
        self::assertSame(45, $data['totalItems'] ?? $data['hydra:totalItems'] ?? null);
        $items = $data['member'] ?? $data['hydra:member'] ?? [];
        self::assertCount(30, $items);

        // Page 2 must be reachable and deliver the remaining items.
        $response2 = static::createClient()->request('GET', '/api/events?page=2');
        self::assertResponseIsSuccessful();
        $items2 = $response2->toArray()['member'] ?? $response2->toArray()['hydra:member'] ?? [];
        self::assertCount(15, $items2);
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

    // --- Name normalisation --------------------------------------------------

    public function testNameIsCapitalisedOnCreate(): void
    {
        $owner = UserFactory::createOne();
        $organization = OrganizationFactory::new()->withMember($owner)->create();

        $client = $this->createAuthenticatedClient($owner);
        $response = $client->request('POST', '/api/events', [
            'json' => [
                'name' => '  forêt de fontainebleau  ',
                'organization' => $this->iriForOrganization($organization),
                'startDate' => '2026-09-01',
                'endDate' => '2026-09-03',
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        // Trimmed AND first letter uppercased — applied at setName so every
        // entry point benefits (manager, raw API, fixtures).
        self::assertJsonContains(['name' => 'Forêt de fontainebleau']);
    }

    public function testNameIsCapitalisedOnPatch(): void
    {
        $owner = UserFactory::createOne();
        $organization = OrganizationFactory::new()->withMember($owner)->create();
        $event = EventFactory::createOne(['organization' => $organization]);

        $client = $this->createAuthenticatedClient($owner);
        $client->request('PATCH', $this->iriFor($event), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'champagne info 2026'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['name' => 'Champagne info 2026']);
    }

    // --- Standalone events (no organization) --------------------------------

    public function testPostCreatesStandaloneEvent(): void
    {
        $creator = UserFactory::createOne();

        $client = $this->createAuthenticatedClient($creator);
        $response = $client->request('POST', '/api/events', [
            'json' => [
                'name' => 'Standalone Run',
                'startDate' => '2026-09-01',
                'endDate' => '2026-09-03',
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $data = $response->toArray();
        self::assertNull($data['organization'] ?? null);
        EventFactory::assert()->count(1);
    }

    public function testPatchStandaloneEventByCreator(): void
    {
        $creator = UserFactory::createOne();
        $event = EventFactory::new()->standalone($creator)->create(['name' => 'Original']);

        $client = $this->createAuthenticatedClient($creator);
        $client->request('PATCH', $this->iriFor($event), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Renamed'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['name' => 'Renamed']);
    }

    public function testPatchStandaloneEventByOtherUserIsForbidden(): void
    {
        $creator = UserFactory::createOne();
        $attacker = UserFactory::createOne();
        $event = EventFactory::new()->standalone($creator)->create(['name' => 'Untouched']);

        $client = $this->createAuthenticatedClient($attacker);
        $client->request('PATCH', $this->iriFor($event), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Hijacked'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testDeleteStandaloneEventByCreator(): void
    {
        $creator = UserFactory::createOne();
        $event = EventFactory::new()->standalone($creator)->create();

        $client = $this->createAuthenticatedClient($creator);
        $client->request('DELETE', $this->iriFor($event));

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        EventFactory::assert()->count(0);
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
