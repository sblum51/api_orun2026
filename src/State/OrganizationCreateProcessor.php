<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\OrganizationInput;
use App\Entity\Organization;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Turns an {@see OrganizationInput} into a persisted {@see Organization},
 * adding the currently authenticated user as the organization's first member.
 *
 * @implements ProcessorInterface<OrganizationInput, Organization>
 */
final readonly class OrganizationCreateProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Organization, Organization> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Organization
    {
        \assert($data instanceof OrganizationInput);

        $creator = $this->security->getUser();
        \assert($creator instanceof User);

        $organization = new Organization((string) $data->name, (string) $data->slug);
        $organization->setDescription($data->description);
        $organization->addMember($creator);

        return $this->persistProcessor->process($organization, $operation, $uriVariables, $context);
    }
}
