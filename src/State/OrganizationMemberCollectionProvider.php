<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Organization;
use App\Entity\OrganizationMember;
use App\Repository\OrganizationMemberRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Returns members of a single organization passed as ?organization=IRI.
 * The caller must manage that organization.
 *
 * @implements ProviderInterface<OrganizationMember>
 */
final readonly class OrganizationMemberCollectionProvider implements ProviderInterface
{
    public function __construct(
        private RequestStack $requestStack,
        private IriConverterInterface $iriConverter,
        private OrganizationMemberRepository $memberRepository,
        private Security $security,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable
    {
        $request = $this->requestStack->getCurrentRequest();
        $iri = $request?->query->get('organization');
        if (!is_string($iri) || '' === $iri) {
            throw new BadRequestHttpException('The "organization" query parameter is required.');
        }

        try {
            $organization = $this->iriConverter->getResourceFromIri($iri);
        } catch (\Throwable) {
            throw new NotFoundHttpException('Organization not found.');
        }
        if (!$organization instanceof Organization) {
            throw new BadRequestHttpException('organization must reference an Organization.');
        }

        if (!$this->security->isGranted('manage', $organization)) {
            throw new AccessDeniedHttpException('You are not a member of this organization.');
        }

        $members = $this->memberRepository->findBy(['organization' => $organization], ['createdAt' => 'ASC']);

        return new TraversablePaginator(new \ArrayIterator($members), 1, \count($members), \count($members));
    }
}
