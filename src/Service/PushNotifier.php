<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\UserDevice;
use App\Enum\DevicePlatform;

/**
 * Unified push facade: takes a UserDevice and an opaque data payload,
 * routes to the right provider (FCM for Android, APNs for iOS), and
 * reports success. Data-only payload on both sides — the app is woken
 * silently and decides what to do with the payload (fetch location,
 * ignore, whatever).
 *
 * Each provider is a separate service so unit tests can substitute a
 * fake without touching the routing logic.
 */
final class PushNotifier
{
    public function __construct(
        private readonly FcmPushClient $fcm,
        private readonly ApnsPushClient $apns,
    ) {
    }

    /**
     * @param array<string, mixed> $data Opaque payload the mobile
     *   handler will read on the other side. Keep values as strings
     *   for maximum cross-platform compatibility (APNs custom keys
     *   accept scalars only, FCM data allows strings only per spec).
     */
    public function send(UserDevice $device, array $data): bool
    {
        return match ($device->getPlatform()) {
            DevicePlatform::Android => $this->fcm->send($device->getPushToken(), $data),
            DevicePlatform::Ios => $this->apns->send($device->getPushToken(), $data),
        };
    }
}
