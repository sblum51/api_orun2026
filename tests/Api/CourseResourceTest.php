<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Course;
use App\Entity\Event;
use App\Enum\CourseType;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\EventFactory;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Component\HttpFoundation\Response;

final class CourseResourceTest extends ApiResourceTestCase
{
    // --- Collection: GET /api/courses ---------------------------------------

    public function testGetCollectionRequiresAuthentication(): void
    {
        static::createClient()->request('GET', '/api/courses');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testGetCollection(): void
    {
        CourseFactory::createMany(3);

        $client = $this->createAuthenticatedClient();
        $response = $client->request('GET', '/api/courses');

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertSame(3, $data['totalItems'] ?? $data['hydra:totalItems'] ?? null);
    }

    // --- Collection: POST /api/courses --------------------------------------

    public function testPostByEventOrgOwnerSucceeds(): void
    {
        $owner = UserFactory::createOne();
        $organization = OrganizationFactory::new()->withMember($owner)->create();
        $event = EventFactory::createOne(['organization' => $organization]);

        $client = $this->createAuthenticatedClient($owner);
        $response = $client->request('POST', '/api/courses', [
            'json' => [
                'name' => 'Circuit Vert',
                'type' => CourseType::Classic->value,
                'event' => $this->iriForEvent($event),
                'distanceKm' => '2.40',
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertJsonContains([
            'name' => 'Circuit Vert',
            'type' => CourseType::Classic->value,
        ]);

        $data = $response->toArray();
        self::assertMatchesRegularExpression('#^/api/courses/[0-9a-f-]{36}$#', $data['@id']);
        CourseFactory::assert()->count(1);
    }

    public function testPostByNonOrgOwnerIsForbidden(): void
    {
        $owner = UserFactory::createOne();
        $attacker = UserFactory::createOne();
        $organization = OrganizationFactory::new()->withMember($owner)->create();
        $event = EventFactory::createOne(['organization' => $organization]);

        $client = $this->createAuthenticatedClient($attacker);
        $client->request('POST', '/api/courses', [
            'json' => [
                'name' => 'Hijack',
                'type' => CourseType::Score->value,
                'event' => $this->iriForEvent($event),
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        CourseFactory::assert()->count(0);
    }

    public function testPostRequiresAuthentication(): void
    {
        $event = EventFactory::createOne();

        static::createClient()->request('POST', '/api/courses', [
            'json' => [
                'name' => 'Nope',
                'type' => CourseType::Classic->value,
                'event' => $this->iriForEvent($event),
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    // --- Item: GET ----------------------------------------------------------

    public function testGetItem(): void
    {
        $course = CourseFactory::createOne(['name' => 'Readable']);

        $client = $this->createAuthenticatedClient();
        $client->request('GET', $this->iriFor($course));

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['name' => 'Readable']);
    }

    public function testGetUnknownReturns404(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/api/courses/00000000-0000-0000-0000-000000000000');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // --- Item: PATCH --------------------------------------------------------

    public function testPatchByOrgOwnerSucceeds(): void
    {
        $owner = UserFactory::createOne();
        $organization = OrganizationFactory::new()->withMember($owner)->create();
        $event = EventFactory::createOne(['organization' => $organization]);
        $course = CourseFactory::createOne(['name' => 'Old', 'event' => $event]);

        $client = $this->createAuthenticatedClient($owner);
        $client->request('PATCH', $this->iriFor($course), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Renamed'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['name' => 'Renamed']);
    }

    public function testPatchByNonOrgOwnerIsForbidden(): void
    {
        $owner = UserFactory::createOne();
        $attacker = UserFactory::createOne();
        $organization = OrganizationFactory::new()->withMember($owner)->create();
        $event = EventFactory::createOne(['organization' => $organization]);
        $course = CourseFactory::createOne(['name' => 'Untouched', 'event' => $event]);

        $client = $this->createAuthenticatedClient($attacker);
        $client->request('PATCH', $this->iriFor($course), [
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
        $event = EventFactory::createOne(['organization' => $organization]);
        $course = CourseFactory::createOne(['name' => 'Moderated', 'event' => $event]);

        $client = $this->createAuthenticatedClient($admin);
        $client->request('PATCH', $this->iriFor($course), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Admin-edited'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['name' => 'Admin-edited']);
    }

    public function testPatchRequiresAuthentication(): void
    {
        $course = CourseFactory::createOne();

        static::createClient()->request('PATCH', $this->iriFor($course), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Nope'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    // --- Item: DELETE -------------------------------------------------------

    public function testDeleteByOrgOwnerSucceeds(): void
    {
        $owner = UserFactory::createOne();
        $organization = OrganizationFactory::new()->withMember($owner)->create();
        $event = EventFactory::createOne(['organization' => $organization]);
        $course = CourseFactory::createOne(['event' => $event]);

        $client = $this->createAuthenticatedClient($owner);
        $client->request('DELETE', $this->iriFor($course));

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        CourseFactory::assert()->count(0);
    }

    public function testDeleteByNonOrgOwnerIsForbidden(): void
    {
        $owner = UserFactory::createOne();
        $attacker = UserFactory::createOne();
        $organization = OrganizationFactory::new()->withMember($owner)->create();
        $event = EventFactory::createOne(['organization' => $organization]);
        $course = CourseFactory::createOne(['event' => $event]);

        $client = $this->createAuthenticatedClient($attacker);
        $client->request('DELETE', $this->iriFor($course));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        CourseFactory::assert()->count(1);
    }

    public function testDeleteRequiresAuthentication(): void
    {
        $course = CourseFactory::createOne();

        static::createClient()->request('DELETE', $this->iriFor($course));

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        CourseFactory::assert()->count(1);
    }

    private function iriFor(Course $course): string
    {
        return '/api/courses/'.$course->getId()->toRfc4122();
    }

    private function iriForEvent(Event $event): string
    {
        return '/api/events/'.$event->getId()->toRfc4122();
    }
}
