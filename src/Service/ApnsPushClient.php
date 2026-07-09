<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Apple Push Notification service (HTTP/2) client, direct — no
 * pushnotify or php-fcm SDK.
 *
 * Auth : signed JWT (ES256) with the .p8 key from Apple Developer +
 * key id + team id. The JWT is cached for 55 minutes (Apple accepts
 * ≤ 60).
 *
 * We send SILENT pushes: apns-push-type header = `background`,
 * `content-available: 1`, no alert/sound/badge. iOS wakes the app for
 * ~30 s of background time to run our handler, subject to the OS's
 * throttling.
 *
 * Required env:
 *   APNS_AUTH_KEY_PATH  Path to the .p8 auth key downloaded from
 *                       Apple Developer.
 *   APNS_KEY_ID         The Key ID shown next to the .p8 in Apple's UI.
 *   APNS_TEAM_ID        Your Apple Developer Team ID.
 *   APNS_BUNDLE_ID      The iOS app bundle id (e.g. dev.blum.orun).
 *   APNS_ENVIRONMENT    `production` (default) or `sandbox`.
 *                       Set sandbox when testing with a debug build
 *                       installed via Xcode / TestFlight.
 */
final class ApnsPushClient
{
    private const JWT_TTL_SEC = 55 * 60;

    private ?string $cachedJwt = null;
    private int $cachedJwtIssuedAt = 0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $authKeyPath,
        private readonly string $keyId,
        private readonly string $teamId,
        private readonly string $bundleId,
        private readonly string $environment = 'production',
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function send(string $deviceToken, array $data): bool
    {
        try {
            $jwt = $this->getJwt();
            $host = 'production' === $this->environment
                ? 'https://api.push.apple.com'
                : 'https://api.sandbox.push.apple.com';
            $url = sprintf('%s/3/device/%s', $host, $deviceToken);

            // Silent-push envelope. `content-available: 1` is the wake
            // trigger; the rest of the JSON body is the runner's
            // handler input. Bundle a `type` field so the handler can
            // route (emergency-locate vs future kinds).
            $body = array_merge($data, [
                'aps' => [
                    'content-available' => 1,
                ],
            ]);

            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'authorization' => 'bearer ' . $jwt,
                    'apns-topic' => $this->bundleId,
                    'apns-push-type' => 'background',
                    'apns-priority' => '5', // 5 = save battery, required for background pushes
                    'content-type' => 'application/json',
                ],
                'body' => json_encode($body, \JSON_THROW_ON_ERROR),
                'http_version' => '2.0',
            ]);
            $status = $response->getStatusCode();
            if ($status !== 200) {
                $this->logger->warning('APNs send failed', [
                    'status' => $status,
                    'body' => $response->getContent(false),
                ]);
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('APNs send exception', ['exception' => $e]);
            return false;
        }
    }

    private function getJwt(): string
    {
        $now = time();
        if ($this->cachedJwt !== null && $now - $this->cachedJwtIssuedAt < self::JWT_TTL_SEC) {
            return $this->cachedJwt;
        }
        if (!is_file($this->authKeyPath) || !is_readable($this->authKeyPath)) {
            throw new \RuntimeException(
                sprintf('APNs auth key not readable at %s', $this->authKeyPath),
            );
        }
        $pkContents = file_get_contents($this->authKeyPath);
        if ($pkContents === false) {
            throw new \RuntimeException('Cannot read APNs auth key.');
        }
        $privateKey = openssl_pkey_get_private($pkContents);
        if ($privateKey === false) {
            throw new \RuntimeException('Invalid APNs .p8 key.');
        }

        $b64 = fn (array $arr): string => rtrim(
            strtr(base64_encode(json_encode($arr, \JSON_THROW_ON_ERROR)), '+/', '-_'),
            '='
        );
        $header = ['alg' => 'ES256', 'kid' => $this->keyId];
        $payload = ['iss' => $this->teamId, 'iat' => $now];
        $signInput = $b64($header) . '.' . $b64($payload);

        // ES256 signature from openssl_sign is DER-encoded; APNs wants
        // the raw R||S concatenation. Convert manually.
        $derSignature = '';
        if (!openssl_sign($signInput, $derSignature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Failed to sign APNs JWT.');
        }
        $rawSignature = $this->derToRawEcdsaSignature($derSignature);
        $jwt = $signInput . '.' . rtrim(strtr(base64_encode($rawSignature), '+/', '-_'), '=');
        $this->cachedJwt = $jwt;
        $this->cachedJwtIssuedAt = $now;
        return $jwt;
    }

    /**
     * Convert a DER-encoded ECDSA signature (as produced by
     * `openssl_sign`) into the raw R||S form (64 bytes for P-256).
     */
    private function derToRawEcdsaSignature(string $der): string
    {
        // DER: 30 <len> 02 <rLen> <r...> 02 <sLen> <s...>
        $offset = 0;
        if (($der[$offset++] ?? '') !== "\x30") {
            throw new \RuntimeException('DER signature: missing sequence tag.');
        }
        $seqLen = \ord($der[$offset++]);
        if ($seqLen & 0x80) {
            $lenBytes = $seqLen & 0x7f;
            $offset += $lenBytes;
        }
        // r
        if (($der[$offset++] ?? '') !== "\x02") {
            throw new \RuntimeException('DER signature: missing r tag.');
        }
        $rLen = \ord($der[$offset++]);
        $r = substr($der, $offset, $rLen);
        $offset += $rLen;
        // s
        if (($der[$offset++] ?? '') !== "\x02") {
            throw new \RuntimeException('DER signature: missing s tag.');
        }
        $sLen = \ord($der[$offset++]);
        $s = substr($der, $offset, $sLen);

        // Strip leading zeros ASN.1 adds to keep values positive,
        // then left-pad to 32 bytes.
        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");
        return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
    }
}
