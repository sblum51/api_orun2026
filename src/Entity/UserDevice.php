<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiableTrait;
use App\Enum\DevicePlatform;
use App\Repository\UserDeviceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Push notification target for one user × platform. Enforced unique so
 * a user has at most one iOS device and one Android device — reflects
 * reality (nobody runs two Orun installs on the same phone) and
 * dodges the "which token do we push to?" question.
 *
 * Not exposed as an ApiResource: a dedicated `POST /api/user-devices`
 * controller does the upsert (upsert semantics don't fit API Platform
 * cleanly).
 */
#[ORM\Entity(repositoryClass: UserDeviceRepository::class)]
#[ORM\Table(name: 'user_devices')]
class UserDevice
{
    use IdentifiableTrait;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 10, enumType: DevicePlatform::class)]
    private DevicePlatform $platform;

    /** APNs device token (hex, ~64-160 chars) or FCM token (up to ~200
     *  chars). 500 caps generously. */
    #[ORM\Column(type: 'string', length: 500)]
    private string $pushToken;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $appVersion = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, DevicePlatform $platform, string $pushToken)
    {
        $this->initializeUuid();
        $this->user = $user;
        $this->platform = $platform;
        $this->pushToken = $pushToken;
        $now = new \DateTimeImmutable();
        $this->lastSeenAt = $now;
        $this->createdAt = $now;
    }

    public function getUser(): User { return $this->user; }
    public function getPlatform(): DevicePlatform { return $this->platform; }
    public function getPushToken(): string { return $this->pushToken; }
    public function getAppVersion(): ?string { return $this->appVersion; }
    public function getLastSeenAt(): \DateTimeImmutable { return $this->lastSeenAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function updatePushToken(string $pushToken, ?string $appVersion): void
    {
        $this->pushToken = $pushToken;
        $this->appVersion = $appVersion;
        $this->lastSeenAt = new \DateTimeImmutable();
    }

    public function touch(): void
    {
        $this->lastSeenAt = new \DateTimeImmutable();
    }
}
