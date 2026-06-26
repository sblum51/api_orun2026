<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\OrganizationMember;
use App\Repository\OrganizationMemberRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Removes a member from an organization, refusing if it would leave the
 * organization without any member.
 *
 * @implements ProcessorInterface<OrganizationMember, void>
 */
final readonly class OrganizationMemberDeleteProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<OrganizationMember, void> $removeProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private ProcessorInterface $removeProcessor,
        private OrganizationMemberRepository $memberRepository,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        \assert($data instanceof OrganizationMember);

        $count = $this->memberRepository->countByOrganization($data->getOrganization());
        if ($count <= 1) {
            throw new ConflictHttpException('Cannot remove the last member of an organization.');
        }

        $this->removeProcessor->process($data, $operation, $uriVariables, $context);
    }
}
