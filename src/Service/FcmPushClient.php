<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Firebase Cloud Messaging HTTP v1 client.
 *
 * Auth : OAuth2 access token from a service-account JSON. Instead of
 * pulling in `google/apiclient` for one call we do the JWT flow
 * ourselves — 30 lines, no dependency creep.
 *
 * We send DATA-ONLY messages ({"data": {...}}). No `notification`
 * block means the OS delivers to `onMessageReceived` in the app
 * without a system tray entry — silent wake-up. Priority `high` gets
 * around Doze-mode delay on Android 6+.
 *
 * Required env vars:
 *   FCM_SERVICE_ACCOUNT_JSON  Path to the Firebase service account key
 *                             (Firebase console → Project settings →
 *                             Service accounts → Generate new key).
 *   FCM_PROJECT_ID            Firebase project ID. Extracted from the
 *                             JSON if omitted.
 */
final class FcmPushClient
{
    private const AUDIENCE = 'https://oauth2.googleapis.com/token';
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const TOKEN_TTL_SEC = 3600;

    private ?string $cachedAccessToken = null;
    private int $cachedAccessTokenExpiresAt = 0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $serviceAccountJsonPath,
        private readonly ?string $overrideProjectId = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function send(string $deviceToken, array $data): bool
    {
        try {
            $accessToken = $this->getAccessToken();
            $projectId = $this->getProjectId();
            $url = sprintf('https://fcm.googleapis.com/v1/projects/%s/messages:send', $projectId);
            // FCM data values MUST be strings. Cast scalars, JSON-encode
            // anything richer — the mobile handler is expected to
            // JSON.parse fields it knows are objects.
            $stringData = [];
            foreach ($data as $k => $v) {
                $stringData[(string) $k] = \is_scalar($v) ? (string) $v : json_encode($v);
            }
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'message' => [
                        'token' => $deviceToken,
                        'data' => $stringData,
                        'android' => [
                            'priority' => 'HIGH',
                        ],
                    ],
                ],
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->logger->warning('FCM send failed', [
                    'status' => $status,
                    'body' => $response->getContent(false),
                ]);
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('FCM send exception', ['exception' => $e]);
            return false;
        }
    }

    private function getAccessToken(): string
    {
        $now = time();
        if ($this->cachedAccessToken !== null && $this->cachedAccessTokenExpiresAt > $now + 60) {
            return $this->cachedAccessToken;
        }

        $sa = $this->readServiceAccount();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $payload = [
            'iss' => $sa['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::AUDIENCE,
            'iat' => $now,
            'exp' => $now + self::TOKEN_TTL_SEC,
        ];
        $b64 = fn (array $arr): string => rtrim(
            strtr(base64_encode(json_encode($arr, \JSON_THROW_ON_ERROR)), '+/', '-_'),
            '='
        );
        $signInput = $b64($header) . '.' . $b64($payload);

        $privateKey = openssl_pkey_get_private($sa['private_key']);
        if ($privateKey === false) {
            throw new \RuntimeException('Invalid FCM private key.');
        }
        $signature = '';
        if (!openssl_sign($signInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Failed to sign FCM JWT.');
        }
        $jwt = $signInput . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        $tokenResponse = $this->httpClient->request('POST', self::AUDIENCE, [
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ],
        ]);
        $tokenBody = $tokenResponse->toArray(false);
        if (!isset($tokenBody['access_token'])) {
            throw new \RuntimeException('FCM token exchange failed: ' . ($tokenBody['error'] ?? 'unknown'));
        }
        $this->cachedAccessToken = $tokenBody['access_token'];
        $this->cachedAccessTokenExpiresAt = $now + (int) ($tokenBody['expires_in'] ?? 3600);
        return $this->cachedAccessToken;
    }

    /**
     * @return array{client_email: string, private_key: string, project_id?: string}
     */
    private function readServiceAccount(): array
    {
        if (!is_file($this->serviceAccountJsonPath) || !is_readable($this->serviceAccountJsonPath)) {
            throw new \RuntimeException(
                sprintf('FCM service account JSON not found or unreadable at %s', $this->serviceAccountJsonPath),
            );
        }
        $raw = file_get_contents($this->serviceAccountJsonPath);
        if ($raw === false) {
            throw new \RuntimeException('Cannot read FCM service account JSON.');
        }
        $data = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($data) || !isset($data['client_email'], $data['private_key'])) {
            throw new \RuntimeException('Malformed FCM service account JSON.');
        }
        return $data;
    }

    private function getProjectId(): string
    {
        if ($this->overrideProjectId !== null && $this->overrideProjectId !== '') {
            return $this->overrideProjectId;
        }
        $sa = $this->readServiceAccount();
        if (!isset($sa['project_id'])) {
            throw new \RuntimeException('project_id missing from FCM service account JSON.');
        }
        return $sa['project_id'];
    }
}
