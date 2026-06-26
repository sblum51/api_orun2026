<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Event;
use App\Entity\User;
use App\Enum\Visibility;
use App\Repository\OrganizationMemberRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Decides whether the authenticated user can view or manage an {@see Event}.
 *
 * - `manage` (write): admin, members of the owning organization, or the standalone creator.
 * - `view`   (read) : admin, manage rights, OR the event is Public. Private events are
 *                     hidden from non-members to mirror the visibility semantics
 *                     ("private" ≈ unlisted / QR-required).
 */
final class EventVoter extends Voter
{
    public const MANAGE = 'manage';
    public const VIEW = 'view';

    public function __construct(
        private readonly OrganizationMemberRepository $memberRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::MANAGE, self::VIEW], true) && $subject instanceof Event;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        \assert($subject instanceof Event);

        // Anonymous: read-only access to Public events only. Mirrors the
        // mobile app's flow where the events list / detail pages are
        // browsable without a login. Private events stay hidden because
        // "private" means QR-required / unlisted.
        if (!$user instanceof User) {
            return self::VIEW === $attribute && Visibility::Public === $subject->getVisibility();
        }

        if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        $canManage = $this->canManage($user, $subject);

        return match ($attribute) {
            self::MANAGE => $canManage,
            self::VIEW => $canManage || Visibility::Public === $subject->getVisibility(),
            default => false,
        };
    }

    private function canManage(User $user, Event $event): bool
    {
        $organization = $event->getOrganization();
        if (null !== $organization) {
            return $this->memberRepository->isUserMemberOf($user, $organization);
        }

        $creator = $event->getCreator();

        return null !== $creator && $creator->getId()->equals($user->getId());
    }
}
