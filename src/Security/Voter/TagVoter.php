<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Tag;
use App\Entity\User;
use App\Repository\OrganizationMemberRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Decides whether the authenticated user can view/manage a {@see Tag}.
 *
 * - `ROLE_ADMIN` always wins.
 * - When the tag is filed under an organization, any member can manage it.
 * - Personal tags (organization=null) are only managed by their creator.
 */
final class TagVoter extends Voter
{
    public const MANAGE = 'manage';
    public const VIEW = 'view';

    public function __construct(
        private readonly OrganizationMemberRepository $memberRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::MANAGE, self::VIEW], true) && $subject instanceof Tag;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        \assert($subject instanceof Tag);

        if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        $organization = $subject->getOrganization();
        if (null !== $organization) {
            return $this->memberRepository->isUserMemberOf($user, $organization);
        }

        $creator = $subject->getCreator();

        return null !== $creator && $creator->getId()->equals($user->getId());
    }
}
