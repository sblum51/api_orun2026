<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends the password-reset email containing a link with the raw token.
 *
 * The raw token is only ever held in memory and in the email — never
 * persisted; the database stores its SHA-256 hash only.
 */
final readonly class PasswordResetEmailer
{
    public function __construct(
        private MailerInterface $mailer,
        #[Autowire('%env(MAILER_FROM)%')]
        private string $fromAddress,
        #[Autowire('%env(APP_FRONT_URL)%')]
        private string $frontUrl,
    ) {
    }

    public function send(User $user, string $rawToken): void
    {
        $link = rtrim($this->frontUrl, '/').'/reset-password?token='.urlencode($rawToken);

        $email = (new Email())
            ->from(new Address($this->fromAddress, 'Orun'))
            ->to($user->getEmail())
            ->subject('Réinitialisation de votre mot de passe')
            ->text(<<<TXT
                Bonjour {$user->getFirstName()},

                Vous avez demandé à réinitialiser votre mot de passe Orun.
                Ouvrez ce lien dans l'heure qui suit pour choisir un nouveau mot de passe :

                {$link}

                Si vous n'êtes pas à l'origine de cette demande, ignorez cet email.
                TXT);

        $this->mailer->send($email);
    }
}
