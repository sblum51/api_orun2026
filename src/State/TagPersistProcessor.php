<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Tag;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Sets the creator on a newly-created {@see Tag} from the authenticated user
 * and enforces "must be a member of the target organization" when one is
 * picked, before delegating to the default Doctrine persist processor.
 *
 * @implements ProcessorInterface<Tag, Tag>
 */
final readonly class TagPersistProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Tag, Tag> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Tag
    {
        \assert($data instanceof Tag);

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Authentication required.');
        }

        if (null === $data->getCreator()) {
            $data->setCreator($user);
        }

        $organization = $data->getOrganization();
        if (null !== $organization && !$this->security->isGranted('manage', $organization)) {
            throw new AccessDeniedHttpException('You can only file tags in an organization you manage.');
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
