<?php

declare(strict_types=1);

namespace App\Service;

use League\Flysystem\FilesystemOperator;

/**
 * Façade over the configured map-image filesystem. The underlying Flysystem
 * operator is selected at container build time — see config/services.yaml,
 * which binds `$storage` to either `map.storage.local` (dev/test) or
 * `map.storage.s3` (production).
 */
final readonly class MapStorage
{
    public function __construct(
        private FilesystemOperator $storage,
        private ?string $publicUrlPrefix = null,
    ) {
    }

    /**
     * Upload bytes under a key (e.g. `events/<uuid>/<sha>.png`) and return the
     * URL the front-end / mobile app should use to fetch the image.
     */
    public function store(string $key, string $bytes, string $contentType = 'application/octet-stream'): string
    {
        $this->storage->write($key, $bytes, ['ContentType' => $contentType]);

        return $this->publicUrl($key);
    }

    /**
     * Like {@see store()} but reads from a disk path so we don't have to
     * materialize the bytes in PHP memory. Suitable for multi-MB KMZ overlays.
     */
    public function storeFile(string $key, string $path, string $contentType = 'application/octet-stream'): string
    {
        $stream = fopen($path, 'rb');
        if (false === $stream) {
            throw new \RuntimeException(sprintf('Cannot open file "%s" for upload.', $path));
        }
        try {
            $this->storage->writeStream($key, $stream, ['ContentType' => $contentType]);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        return $this->publicUrl($key);
    }

    public function publicUrl(string $key): string
    {
        $prefix = $this->publicUrlPrefix ?? '';
        if ('' === $prefix) {
            return '/uploads/maps/'.ltrim($key, '/');
        }

        return rtrim($prefix, '/').'/'.ltrim($key, '/');
    }

    public function delete(string $key): void
    {
        if ($this->storage->fileExists($key)) {
            $this->storage->delete($key);
        }
    }

    /**
     * Returns the raw bytes of a stored object, or null when the key doesn't
     * exist. Used by the maintenance command that re-processes existing
     * GroundOverlay images.
     */
    public function read(string $key): ?string
    {
        if (!$this->storage->fileExists($key)) {
            return null;
        }

        return $this->storage->read($key);
    }

    /**
     * Reverse {@see publicUrl()}: given a public URL produced earlier, return
     * the Flysystem key that points to the same object. Returns null when the
     * URL doesn't match the configured prefix (i.e. it lives in some other
     * bucket or is a third-party URL).
     */
    public function keyFromUrl(string $url): ?string
    {
        $prefix = $this->publicUrlPrefix ?? '';
        if ('' === $prefix) {
            if (str_starts_with($url, '/uploads/maps/')) {
                return substr($url, \strlen('/uploads/maps/'));
            }

            return null;
        }
        $normalized = rtrim($prefix, '/').'/';
        if (str_starts_with($url, $normalized)) {
            return substr($url, \strlen($normalized));
        }

        return null;
    }
}
