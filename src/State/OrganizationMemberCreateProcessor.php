<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\OrganizationMemberInput;
use App\Entity\Organization;
use App\Entity\OrganizationMember;
use App\Repository\OrganizationMemberRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Adds a user (looked up by email) as a member of an organization.
 *
 * @implements ProcessorInterface<OrganizationMemberInput, OrganizationMember>
 */
final readonly class OrganizationMemberCreateProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<OrganizationMember, OrganizationMember> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private IriConverterInterface $iriConverter,
        private UserRepository $userRepository,
        private OrganizationMemberRepository $memberRepository,
        private Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): OrganizationMember
    {
        \assert($data instanceof OrganizationMemberInput);

        if (null === $data->organization) {
            throw new UnprocessableEntityHttpException('organization is required.');
        }

        try {
            $organization = $this->iriConverter->getResourceFromIri($data->organization);
        } catch (\Throwable) {
            throw new NotFoundHttpException('Organization not found.');
        }
        if (!$organization instanceof Organization) {
            throw new UnprocessableEntityHttpException('organization must reference an Organization.');
        }

        if (!$this->security->isGranted('manage', $organization)) {
            throw new AccessDeniedHttpException('You are not a member of this organization.');
        }

        $user = $this->userRepository->findOneBy(['email' => $data->email]);
        if (null === $user) {
            throw new NotFoundHttpException('No user with this email.');
        }

        if ($this->memberRepository->isUserMemberOf($user, $organization)) {
            throw new ConflictHttpException('This user is already a member of this organization.');
        }

        $member = new OrganizationMember($organization, $user);

        return $this->persistProcessor->process($member, $operation, $uriVariables, $context);
    }
}
