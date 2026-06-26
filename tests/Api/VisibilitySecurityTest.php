<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Enum\CourseType;
use App\Enum\Visibility;
use App\Tests\Factory\ActivityFactory;
use App\Tests\Factory\ControlFactory;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\EventFactory;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asserts that the visibility-based authorization rules added on Event /
 * Course / Control / ranking actually hide private resources from
 * authenticated callers who don't manage them.
 */
final class VisibilitySecurityTest extends ApiResourceTestCase
{
    // --- Event ------------------------------------------------------------

    public function testGetPrivateEventForbiddenForNonMember(): void
    {
        $owner = UserFactory::createOne();
        $attacker = UserFactory::createOne();
        $org = OrganizationFactory::new()->withMember($owner)->create();
        $event = EventFactory::new()
            ->withCreator($owner)
            ->create(['organization' => $org, 'visibility' => Visibility::Private]);

        $client = $this->createAuthenticatedClient($attacker);
        $client->request('GET', '/api/events/'.$event->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testGetPrivateEventAllowedForMember(): void
    {
        $owner = UserFactory::createOne();
        $org = OrganizationFactory::new()->withMember($owner)->create();
        $event = EventFactory::new()
            ->withCreator($owner)
            ->create(['organization' => $org, 'visibility' => Visibility::Private]);

        $client = $this->createAuthenticatedClient($owner);
        $client->request('GET', '/api/events/'.$event->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
    }

    public function testGetCollectionHidesPrivateEventsFromNonMembers(): void
    {
        $owner = UserFactory::createOne();
        $attacker = UserFactory::createOne();
        $org = OrganizationFactory::new()->withMember($owner)->create();

        EventFactory::new()->withCreator($owner)->create(['organization' => $org, 'visibility' => Visibility::Public]);
        EventFactory::new()->withCreator($owner)->create(['organization' => $org, 'visibility' => Visibility::Private]);
        EventFactory::new()->withCreator($owner)->create(['organization' => $org, 'visibility' => Visibility::Private]);

        $client = $this->createAuthenticatedClient($attacker);
        $data = $client->request('GET', '/api/events')->toArray();

        self::assertSame(1, $data['totalItems'] ?? $data['hydra:totalItems'] ?? null);
    }

    public function testGetCollectionShowsAllToOwningMember(): void
    {
        $owner = UserFactory::createOne();
        $org = OrganizationFactory::new()->withMember($owner)->create();

        EventFactory::new()->withCreator($owner)->create(['organization' => $org, 'visibility' => Visibility::Public]);
        EventFactory::new()->withCreator($owner)->create(['organization' => $org, 'visibility' => Visibility::Private]);

        $client = $this->createAuthenticatedClient($owner);
        $data = $client->request('GET', '/api/events')->toArray();

        self::assertSame(2, $data['totalItems'] ?? $data['hydra:totalItems'] ?? null);
    }

    // --- Course -----------------------------------------------------------

    public function testGetPrivateCourseForbiddenForNonMember(): void
    {
        $owner = UserFactory::createOne();
        $attacker = UserFactory::createOne();
        $org = OrganizationFactory::new()->withMember($owner)->create();
        $event = EventFactory::new()->withCreator($owner)->create(['organization' => $org, 'visibility' => Visibility::Public]);
        $course = CourseFactory::createOne(['event' => $event, 'visibility' => Visibility::Private]);

        $client = $this->createAuthenticatedClient($attacker);
        $client->request('GET', '/api/courses/'.$course->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testGetCollectionHidesPrivateCoursesFromNonMembers(): void
    {
        $owner = UserFactory::createOne();
        $attacker = UserFactory::createOne();
        $org = OrganizationFactory::new()->withMember($owner)->create();
        $publicEvent = EventFactory::new()->withCreator($owner)->create(['organization' => $org, 'visibility' => Visibility::Public]);

        CourseFactory::createOne(['event' => $publicEvent, 'visibility' => Visibility::Public]);
        CourseFactory::createOne(['event' => $publicEvent, 'visibility' => Visibility::Private]);

        $client = $this->createAuthenticatedClient($attacker);
        $data = $client->request('GET', '/api/courses')->toArray();

        self::assertSame(1, $data['totalItems'] ?? $data['hydra:totalItems'] ?? null);
    }

    public function testCourseInsidePrivateEventIsHiddenEvenIfCoursePublic(): void
    {
        $owner = UserFactory::createOne();
        $attacker = UserFactory::createOne();
        $org = OrganizationFactory::new()->withMember($owner)->create();
        $privateEvent = EventFactory::new()->withCreator($owner)->create(['organization' => $org, 'visibility' => Visibility::Private]);
        CourseFactory::createOne(['event' => $privateEvent, 'visibility' => Visibility::Public]);

        $client = $this->createAuthenticatedClient($attacker);
        $data = $client->request('GET', '/api/courses')->toArray();

        self::assertSame(0, $data['totalItems'] ?? $data['hydra:totalItems'] ?? null);
    }

    // --- Control ----------------------------------------------------------

    public function testGetControlInPrivateEventForbiddenForNonMember(): void
    {
        $owner = UserFactory::createOne();
        $attacker = UserFactory::createOne();
        $org = OrganizationFactory::new()->withMember($owner)->create();
        $event = EventFactory::new()->withCreator($owner)->create(['organization' => $org, 'visibility' => Visibility::Private]);
        $control = ControlFactory::createOne(['event' => $event]);

        $client = $this->createAuthenticatedClient($attacker);
        $client->request('GET', '/api/controls/'.$control->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testGetCollectionHidesControlsFromPrivateEvent(): void
    {
        $owner = UserFactory::createOne();
        $attacker = UserFactory::createOne();
        $org = OrganizationFactory::new()->withMember($owner)->create();
        $event = EventFactory::new()->withCreator($owner)->create(['organization' => $org, 'visibility' => Visibility::Private]);
        ControlFactory::createOne(['event' => $event, 'code' => 31]);
        ControlFactory::createOne(['event' => $event, 'code' => 32]);

        $client = $this->createAuthenticatedClient($attacker);
        $data = $client->request('GET', '/api/controls')->toArray();

        self::assertSame(0, $data['totalItems'] ?? $data['hydra:totalItems'] ?? null);
    }

    // --- Ranking ----------------------------------------------------------

    public function testRankingOnPrivateCourseForbiddenForNonMember(): void
    {
        $owner = UserFactory::createOne();
        $attacker = UserFactory::createOne();
        $org = OrganizationFactory::new()->withMember($owner)->create();
        $event = EventFactory::new()->withCreator($owner)->create(['organization' => $org, 'visibility' => Visibility::Public]);
        $course = CourseFactory::createOne([
            'event' => $event,
            'visibility' => Visibility::Private,
            'type' => CourseType::Classic,
        ]);
        ActivityFactory::createOne(['course' => $course]);

        $client = $this->createAuthenticatedClient($attacker);
        $client->request('GET', '/api/courses/'.$course->getId()->toRfc4122().'/ranking');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }
}
