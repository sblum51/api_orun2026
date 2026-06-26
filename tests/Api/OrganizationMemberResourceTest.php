<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Organization;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Component\HttpFoundation\Response;

final class OrganizationMemberResourceTest extends ApiResourceTestCase
{
    // --- Collection: GET /api/organization_members?organization=... ---------

    public function testListMembersByOrganization(): void
    {
        $owner = UserFactory::createOne();
        $organization = OrganizationFactory::new()
            ->withMember($owner)
            ->create();
        $expectedCount = $organization->getMembers()->count();

        $client = $this->createAuthenticatedClient($owner);
        $response = $client->request('GET', '/api/organization_members', [
            'query' => ['organization' => $this->orgIri($organization)],
        ]);

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertSame($expectedCount, $data['totalItems'] ?? $data['hydra:totalItems'] ?? null);
    }

    public function testListMembersByNonMemberIsForbidden(): void
    {
        $owner = UserFactory::createOne();
        $attacker = UserFactory::createOne();
        $organization = OrganizationFactory::new()->withMember($owner)->create();

        $client = $this->createAuthenticatedClient($attacker);
        $client->request('GET', '/api/organization_members', [
            'query' => ['organization' => $this->orgIri($organization)],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testListMembersRequiresOrganizationQuery(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/api/organization_members');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    // --- Collection: POST /api/organization_members -------------------------

    public function testAddMemberByEmail(): void
    {
        $owner = UserFactory::createOne();
        UserFactory::createOne(['email' => 'new@orun.dev']);
        $organization = OrganizationFactory::new()->withMember($owner)->create();
        $before = $organization->getMembers()->count();

        $client = $this->createAuthenticatedClient($owner);
        $client->request('POST', '/api/organization_members', [
            'json' => [
                'email' => 'new@orun.dev',
                'organization' => $this->orgIri($organization),
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertJsonContains([
            'user' => ['email' => 'new@orun.dev'],
        ]);

        $client->request('GET', '/api/organization_members', [
            'query' => ['organization' => $this->orgIri($organization)],
        ]);
        $data = $client->getResponse()->toArray();
        self::assertSame($before + 1, $data['totalItems'] ?? $data['hydra:totalItems'] ?? null);
    }

    public function testAddMemberByNonManagerIsForbidden(): void
    {
        $owner = UserFactory::createOne();
        $attacker = UserFactory::createOne();
        UserFactory::createOne(['email' => 'victim@orun.dev']);
        $organization = OrganizationFactory::new()->withMember($owner)->create();

        $client = $this->createAuthenticatedClient($attacker);
        $client->request('POST', '/api/organization_members', [
            'json' => [
                'email' => 'victim@orun.dev',
                'organization' => $this->orgIri($organization),
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAddMemberWithUnknownEmailReturns404(): void
    {
        $owner = UserFactory::createOne();
        $organization = OrganizationFactory::new()->withMember($owner)->create();

        $client = $this->createAuthenticatedClient($owner);
        $client->request('POST', '/api/organization_members', [
            'json' => [
                'email' => 'ghost@orun.dev',
                'organization' => $this->orgIri($organization),
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAddMemberAlreadyPresentReturns409(): void
    {
        $owner = UserFactory::createOne(['email' => 'owner@orun.dev']);
        $organization = OrganizationFactory::new()->withMember($owner)->create();

        $client = $this->createAuthenticatedClient($owner);
        $client->request('POST', '/api/organization_members', [
            'json' => [
                'email' => 'owner@orun.dev',
                'organization' => $this->orgIri($organization),
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    // --- Item: DELETE /api/organization_members/{id} ------------------------

    public function testRemoveMember(): void
    {
        $owner = UserFactory::createOne();
        $extra = UserFactory::createOne();
        $organization = OrganizationFactory::new()
            ->withMember($owner)
            ->withMember($extra)
            ->create();
        $before = $organization->getMembers()->count();

        $extraMember = null;
        foreach ($organization->getMembers() as $member) {
            if ($member->getUser()->getId()->equals($extra->getId())) {
                $extraMember = $member;
                break;
            }
        }
        self::assertNotNull($extraMember);

        $client = $this->createAuthenticatedClient($owner);
        $client->request('DELETE', '/api/organization_members/'.$extraMember->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $client->request('GET', '/api/organization_members', [
            'query' => ['organization' => $this->orgIri($organization)],
        ]);
        $data = $client->getResponse()->toArray();
        self::assertSame($before - 1, $data['totalItems'] ?? $data['hydra:totalItems'] ?? null);
    }

    public function testRemoveLastMemberIsRefused(): void
    {
        // Organization with a single member — its sole owner.
        $owner = UserFactory::createOne();
        $organization = OrganizationFactory::new()->withMember($owner)->create();

        // Some factory hooks add a default user too, so first reduce to exactly 1.
        $members = $organization->getMembers();
        $ownerMember = null;
        foreach ($members as $member) {
            if ($member->getUser()->getId()->equals($owner->getId())) {
                $ownerMember = $member;
                break;
            }
        }
        self::assertNotNull($ownerMember);

        // If there are multiple members, drop the others through DB so only owner remains.
        $em = static::getContainer()->get('doctrine')->getManager();
        foreach ($members as $member) {
            if ($member !== $ownerMember) {
                $em->remove($member);
            }
        }
        $em->flush();

        $client = $this->createAuthenticatedClient($owner);
        $client->request('DELETE', '/api/organization_members/'.$ownerMember->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testRemoveByNonManagerIsForbidden(): void
    {
        $owner = UserFactory::createOne();
        $extra = UserFactory::createOne();
        $attacker = UserFactory::createOne();
        $organization = OrganizationFactory::new()
            ->withMember($owner)
            ->withMember($extra)
            ->create();

        $extraMember = null;
        foreach ($organization->getMembers() as $member) {
            if ($member->getUser()->getId()->equals($extra->getId())) {
                $extraMember = $member;
                break;
            }
        }
        self::assertNotNull($extraMember);

        $client = $this->createAuthenticatedClient($attacker);
        $client->request('DELETE', '/api/organization_members/'.$extraMember->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        unset($owner);
    }

    private function orgIri(Organization $organization): string
    {
        return '/api/organizations/'.$organization->getId()->toRfc4122();
    }
}
