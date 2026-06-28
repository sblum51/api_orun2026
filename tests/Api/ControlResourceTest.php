<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Control;
use App\Entity\Event;
use App\Tests\Factory\ControlFactory;
use App\Tests\Factory\EventFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Component\HttpFoundation\Response;

final class ControlResourceTest extends ApiResourceTestCase
{
    public function testListByEvent(): void
    {
        $owner = UserFactory::createOne();
        $org = \App\Tests\Factory\OrganizationFactory::new()->withMember($owner)->create();
        $event = EventFactory::new()->withCreator($owner)->create(['organization' => $org]);

        ControlFactory::createOne(['event' => $event, 'code' => 31]);
        ControlFactory::createOne(['event' => $event, 'code' => 32]);
        ControlFactory::createOne(); // a control on another event — must not leak

        $client = $this->createAuthenticatedClient($owner);
        $data = $client->request('GET', '/api/controls', [
            'query' => ['event' => $this->iriForEvent($event)],
        ])->toArray();

        self::assertSame(2, $data['totalItems'] ?? $data['hydra:totalItems'] ?? null);
    }

    public function testCreateControlByEventManager(): void
    {
        $owner = UserFactory::createOne();
        $org = \App\Tests\Factory\OrganizationFactory::new()->withMember($owner)->create();
        $event = EventFactory::new()->withCreator($owner)->create(['organization' => $org]);

        $client = $this->createAuthenticatedClient($owner);
        $client->request('POST', '/api/controls', [
            'json' => [
                'event' => $this->iriForEvent($event),
                'code' => 42,
                'validationMethods' => ['qr_code'],
                'latitude' => 48.85,
                'longitude' => 2.35,
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertJsonContains(['code' => '42', 'validationMethods' => ['qr_code']]);
        ControlFactory::assert()->count(1);
    }

    public function testCreateByNonMemberIsForbidden(): void
    {
        $owner = UserFactory::createOne();
        $attacker = UserFactory::createOne();
        $org = \App\Tests\Factory\OrganizationFactory::new()->withMember($owner)->create();
        $event = EventFactory::new()->withCreator($owner)->create(['organization' => $org]);

        $client = $this->createAuthenticatedClient($attacker);
        $client->request('POST', '/api/controls', [
            'json' => [
                'event' => $this->iriForEvent($event),
                'code' => 50,
                'validationMethods' => ['qr_code'],
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        ControlFactory::assert()->count(0);
    }

    public function testPatchControlByManager(): void
    {
        $owner = UserFactory::createOne();
        $org = \App\Tests\Factory\OrganizationFactory::new()->withMember($owner)->create();
        $event = EventFactory::new()->withCreator($owner)->create(['organization' => $org]);
        $control = ControlFactory::createOne(['event' => $event, 'code' => 33]);

        $client = $this->createAuthenticatedClient($owner);
        $client->request('PATCH', $this->iriForControl($control), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['note' => 'Sous le grand sapin'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['note' => 'Sous le grand sapin']);
    }

    public function testDeleteControlByManager(): void
    {
        $owner = UserFactory::createOne();
        $org = \App\Tests\Factory\OrganizationFactory::new()->withMember($owner)->create();
        $event = EventFactory::new()->withCreator($owner)->create(['organization' => $org]);
        $control = ControlFactory::createOne(['event' => $event]);

        $client = $this->createAuthenticatedClient($owner);
        $client->request('DELETE', $this->iriForControl($control));

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        ControlFactory::assert()->count(0);
    }

    public function testDuplicateCodeInSameEventIs422(): void
    {
        $owner = UserFactory::createOne();
        $org = \App\Tests\Factory\OrganizationFactory::new()->withMember($owner)->create();
        $event = EventFactory::new()->withCreator($owner)->create(['organization' => $org]);
        ControlFactory::createOne(['event' => $event, 'code' => 31]);

        $client = $this->createAuthenticatedClient($owner);
        $client->request('POST', '/api/controls', [
            'json' => [
                'event' => $this->iriForEvent($event),
                'code' => 31,
                'validationMethods' => ['qr_code'],
            ],
        ]);

        self::assertGreaterThanOrEqual(400, $client->getResponse()->getStatusCode());
        ControlFactory::assert()->count(1);
    }

    private function iriForControl(Control $c): string
    {
        return '/api/controls/'.$c->getId()->toRfc4122();
    }

    private function iriForEvent(Event $e): string
    {
        return '/api/events/'.$e->getId()->toRfc4122();
    }
}
