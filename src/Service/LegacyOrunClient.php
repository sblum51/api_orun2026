<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin HTTP wrapper around the historical Orun API
 * (`https://api.orun.app`). Exposes the two shapes the importer
 * needs:
 *
 *   1. Search — `GET /events?…` returning a Hydra collection
 *   2. Single — `GET /events/{slug}` returning one event with courses
 *      (and their maps) embedded
 *
 * The base URL is configurable via env for testing against staging.
 * Every response is decoded as an array — the calling importer picks
 * the fields it cares about.
 */
final class LegacyOrunClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $baseUrl = 'https://api.orun.app',
    ) {
    }

    /**
     * Passes through a query-string map to the legacy `/events`
     * endpoint. Common combinations the manager UI ships:
     *   ['order[createdAt]' => 'desc', 'enabled' => 'true',
     *    'apps' => 'orun_app', 'settings.availableInListings' => 'true']
     *
     * @param array<string, string|int|bool> $query
     * @return array<mixed> Raw decoded body
     */
    public function browseEvents(array $query = []): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->baseUrl . '/events', [
                'query' => $query,
                'timeout' => 15,
            ]);
            return $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('Legacy browse failed', ['exception' => $e]);
            return [];
        }
    }

    /**
     * @return array<mixed> Raw decoded body of the event page. Throws
     * NotFound when the legacy API returns 404 so the caller doesn't
     * silently import an empty record.
     */
    public function getEventBySlug(string $slug): array
    {
        $response = $this->httpClient->request('GET', $this->baseUrl . '/events/' . $slug, [
            'timeout' => 15,
        ]);
        $status = $response->getStatusCode();
        if ($status === 404) {
            throw new NotFoundHttpException(sprintf('Legacy event "%s" not found.', $slug));
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf(
                'Legacy fetch failed (%d): %s',
                $status,
                $response->getContent(false),
            ));
        }
        return $response->toArray(false);
    }

    /**
     * Download a legacy asset (course map image, event cover…) into a
     * local temp file and return its path + mime. Caller is
     * responsible for `unlink()`.
     *
     * @return array{path: string, mime: string, ext: string}|null null on failure.
     */
    public function downloadAsset(string $url): ?array
    {
        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 30]);
            if ($response->getStatusCode() !== 200) {
                $this->logger->warning('Legacy asset download failed', [
                    'url' => $url,
                    'status' => $response->getStatusCode(),
                ]);
                return null;
            }
            $content = $response->getContent(false);
            $tmp = tempnam(sys_get_temp_dir(), 'orun-legacy-');
            if ($tmp === false) {
                return null;
            }
            file_put_contents($tmp, $content);

            $info = @getimagesize($tmp);
            if ($info === false) {
                // Non-image (PDF etc.) — inspect Content-Type header.
                $headers = $response->getHeaders(false);
                $mime = strtolower($headers['content-type'][0] ?? 'application/octet-stream');
                $mime = trim(explode(';', $mime)[0]);
                $ext = match ($mime) {
                    'application/pdf' => 'pdf',
                    default => 'bin',
                };
                return ['path' => $tmp, 'mime' => $mime, 'ext' => $ext];
            }
            [$mime, $ext] = match ($info[2]) {
                \IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
                \IMAGETYPE_PNG => ['image/png', 'png'],
                \IMAGETYPE_WEBP => ['image/webp', 'webp'],
                default => ['application/octet-stream', 'bin'],
            };
            return ['path' => $tmp, 'mime' => $mime, 'ext' => $ext];
        } catch (\Throwable $e) {
            $this->logger->warning('Legacy asset exception', ['url' => $url, 'exception' => $e]);
            return null;
        }
    }
}
