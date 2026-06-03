<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Organization;
use App\Entity\User;
use App\Repository\OrganizationMemberRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Decides whether the authenticated user can manage an {@see Organization}.
 *
 * - `ROLE_ADMIN` always wins.
 * - Any user listed as a member of the organization can manage it.
 *
 * The "owner" concept does not exist anymore; the first user added becomes the
 * sole member, and additional managers can be added later via the
 * `OrganizationMember` join entity.
 */
final class OrganizationVoter extends Voter
{
    public const MANAGE = 'manage';

    public function __construct(
        private readonly OrganizationMemberRepository $memberRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MANAGE === $attribute && $subject instanceof Organization;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        \assert($subject instanceof Organization);

        return $this->memberRepository->isUserMemberOf($user, $subject);
    }
}
