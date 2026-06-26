<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Course;
use App\Entity\User;
use App\Enum\Visibility;
use App\Repository\OrganizationMemberRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Decides whether the authenticated user can view or manage a {@see Course}.
 *
 * - `manage` (write): same rule as the parent event.
 * - `view`   (read) : manage rights OR course is Public AND parent event is viewable.
 *                     Private courses inside a Public event are still hidden.
 */
final class CourseVoter extends Voter
{
    public const MANAGE = 'manage';
    public const VIEW = 'view';

    public function __construct(
        private readonly OrganizationMemberRepository $memberRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::MANAGE, self::VIEW], true) && $subject instanceof Course;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        \assert($subject instanceof Course);

        // Anonymous: read-only on Public courses inside Public events. The
        // mobile app needs this to walk an event → circuits → run screen
        // without a login.
        if (!$user instanceof User) {
            return self::VIEW === $attribute
                && Visibility::Public === $subject->getVisibility()
                && Visibility::Public === $subject->getEvent()->getVisibility();
        }

        if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        $event = $subject->getEvent();
        $canManageEvent = $this->canManageEvent($user, $event);

        return match ($attribute) {
            self::MANAGE => $canManageEvent,
            self::VIEW => $canManageEvent
                || (Visibility::Public === $subject->getVisibility() && Visibility::Public === $event->getVisibility()),
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
