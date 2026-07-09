<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserDevice;
use App\Enum\DevicePlatform;
use App\Repository\UserDeviceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Upsert the caller's push token for the given platform. Called by the
 * mobile at login and whenever the token rotates (Firebase / APNs
 * refresh their tokens periodically). Unique on (user, platform) so a
 * device replacement (new phone) transparently replaces the row.
 */
final class RegisterUserDeviceController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly UserDeviceRepository $devices,
    ) {
    }

    #[Route(
        path: '/api/user-devices',
        name: 'api_user_devices_register',
        methods: ['POST'],
        format: 'json',
    )]
    #[IsGranted('ROLE_USER')]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Missing authenticated user.');
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!\is_array($payload)) {
            throw new BadRequestHttpException('Body must be a JSON object.');
        }
        $platform = DevicePlatform::tryFrom((string) ($payload['platform'] ?? ''));
        $pushToken = $payload['pushToken'] ?? null;
        $appVersion = $payload['appVersion'] ?? null;
        if ($platform === null || !\is_string($pushToken) || $pushToken === '') {
            throw new BadRequestHttpException('platform (ios|android) and pushToken required.');
        }
        if (mb_strlen($pushToken) > 500) {
            throw new BadRequestHttpException('pushToken too long.');
        }
        if ($appVersion !== null && (!\is_string($appVersion) || mb_strlen($appVersion) > 50)) {
            throw new BadRequestHttpException('appVersion must be a short string.');
        }

        $existing = $this->devices->findOneBy(['user' => $user, 'platform' => $platform]);
        if ($existing === null) {
            $device = new UserDevice($user, $platform, $pushToken);
            if ($appVersion !== null) {
                $device->updatePushToken($pushToken, $appVersion);
            }
            $this->em->persist($device);
        } else {
            $existing->updatePushToken($pushToken, $appVersion);
        }
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }
}
