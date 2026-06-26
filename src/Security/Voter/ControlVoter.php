<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Control;
use App\Entity\User;
use App\Enum\Visibility;
use App\Repository\OrganizationMemberRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Decides whether the authenticated user can view or manage a {@see Control}.
 *
 * Inherits the visibility/membership rule of the parent {@see \App\Entity\Event}.
 */
final class ControlVoter extends Voter
{
    public const MANAGE = 'manage';
    public const VIEW = 'view';

    public function __construct(
        private readonly OrganizationMemberRepository $memberRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::MANAGE, self::VIEW], true) && $subject instanceof Control;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        \assert($subject instanceof Control);
        $event = $subject->getEvent();
        if (null === $event) {
            return false;
        }

        // Anonymous: read-only on controls of Public events. Same flow as
        // the Event/Course voters — the runner can fetch what's needed
        // without a login.
        if (!$user instanceof User) {
            return self::VIEW === $attribute && Visibility::Public === $event->getVisibility();
        }

        if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        $canManage = $this->canManageEvent($user, $event);

        return match ($attribute) {
            self::MANAGE => $canManage,
            self::VIEW => $canManage || Visibility::Public === $event->getVisibility(),
            default => false,
        };
    }

    private function canManageEvent(User $user, \App\Entity\Event $event): bool
    {
        $organization = $event->getOrganization();
        if (null !== $organization) {
            return $this->memberRepository->isUserMemberOf($user, $organization);
        }

        $creator = $event->getCreator();

        return null !== $creator && $creator->getId()->equals($user->getId());
    }
}
