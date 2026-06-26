<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\MapRepository;
use App\Service\MapStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-shot maintenance: iterates every GroundOverlay image referenced by a
 * {@see \App\Entity\Map}, reads it from MapStorage (local disk or S3/OVH
 * depending on env config), downscales + optionally re-encodes it (defaults
 * to WebP — half the size of PNG for orienteering maps with no visible
 * quality loss), and writes it back. Map records are updated to point to
 * the new URL when the extension changes; the original file is left in
 * place unless `--delete-source` is set.
 *
 * Idempotent: re-running on already-small WebP images is a no-op.
 */
#[AsCommand(
    name: 'app:maps:downscale',
    description: 'Downscale + re-encode GroundOverlay images already stored in MapStorage.',
)]
final class DownscaleMapImagesCommand extends Command
{
    private const FORMATS = ['webp', 'jpeg', 'png', 'keep'];

    public function __construct(
        private readonly MapRepository $mapRepository,
        private readonly MapStorage $mapStorage,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('max-dim', null, InputOption::VALUE_REQUIRED, 'Max width/height in pixels.', '2048')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: '.implode('|', self::FORMATS), 'webp')
            ->addOption('quality', null, InputOption::VALUE_REQUIRED, 'JPEG/WebP quality (1-100).', '85')
            ->addOption('delete-source', null, InputOption::VALUE_NONE, 'Delete the original file once Map records have been migrated.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing anything.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $maxDim = max(64, (int) $input->getOption('max-dim'));
        $format = (string) $input->getOption('format');
        $quality = max(1, min(100, (int) $input->getOption('quality')));
        $deleteSource = (bool) $input->getOption('delete-source');
        $dryRun = (bool) $input->getOption('dry-run');

        if (!\in_array($format, self::FORMATS, true)) {
            $io->error(sprintf('Unknown --format=%s. Use one of: %s.', $format, implode(', ', self::FORMATS)));

            return Command::FAILURE;
        }
        if (!\function_exists('imagecreatefromstring')) {
            $io->error('PHP GD is not installed — install ext-gd to use this command.');

            return Command::FAILURE;
        }
        if ('webp' === $format && !\function_exists('imagewebp')) {
            $io->error('PHP GD lacks WebP support — recompile with --with-webp or pick another --format.');

            return Command::FAILURE;
        }

        $uniqueUrls = [];
        foreach ($this->mapRepository->findAll() as $map) {
            $uniqueUrls[$map->getImageUrl()] = true;
        }
        $io->writeln(sprintf('Found %d unique image URLs referenced by Map records.', \count($uniqueUrls)));

        $touched = 0;
        $skipped = 0;
        $missing = 0;
        $savedBytes = 0;
        $updatedRecords = 0;

        foreach (array_keys($uniqueUrls) as $oldUrl) {
            $oldKey = $this->mapStorage->keyFromUrl($oldUrl);
            if (null === $oldKey) {
                $io->writeln(sprintf('  <comment>skip</comment> %s — URL not on our storage', $oldUrl));
                ++$skipped;
                continue;
            }
            $bytes = $this->mapStorage->read($oldKey);
            if (null === $bytes) {
                $io->writeln(sprintf('  <error>404</error> %s', $oldKey));
                ++$missing;
                continue;
            }

            $info = $this->dimensions($bytes);
            if (null === $info) {
                $io->writeln(sprintf('  <comment>skip</comment> %s — not a decodable image', $oldKey));
                ++$skipped;
                continue;
            }
            [$w, $h] = $info;

            $needsResize = $w > $maxDim || $h > $maxDim;
            $oldExt = $this->extFromKey($oldKey);
            $newExt = 'keep' === $format ? $oldExt : ('jpeg' === $format ? 'jpg' : $format);
            $needsRecode = $newExt !== $oldExt;
            if (!$needsResize && !$needsRecode) {
                $io->writeln(sprintf('  <info>ok  </info> %s — %dx%d %s, no change', $oldKey, $w, $h, $oldExt));
                ++$skipped;
                continue;
            }

            $ratio = $needsResize ? ($maxDim / max($w, $h)) : 1.0;
            $newW = max(1, (int) round($w * $ratio));
            $newH = max(1, (int) round($h * $ratio));
            $beforeBytes = \strlen($bytes);
            $newKey = $needsRecode
                ? preg_replace('/\.[^.]+$/', '.'.$newExt, $oldKey) ?? $oldKey
                : $oldKey;

            $io->writeln(sprintf(
                '  <info>%s</info> %s — %dx%d %s → %dx%d %s%s',
                $needsRecode ? 'rewrite' : 'resize ',
                $oldKey,
                $w,
                $h,
                $oldExt,
                $newW,
                $newH,
                $newExt,
                $dryRun ? ' (dry-run)' : '',
            ));

            if ($dryRun) {
                continue;
            }

            $newBytes = $this->encode($bytes, $newW, $newH, $newExt, $quality);
            if (null === $newBytes) {
                $io->writeln(sprintf('  <error>failed</error> %s', $oldKey));
                continue;
            }

            $newUrl = $this->mapStorage->store($newKey, $newBytes, $this->mimeForExt($newExt));

            if ($newUrl !== $oldUrl) {
                $migrated = $this->migrateMapRecords($oldUrl, $newUrl);
                $updatedRecords += $migrated;
                $io->writeln(sprintf('    → updated %d Map row%s', $migrated, $migrated > 1 ? 's' : ''));
                if ($deleteSource) {
                    $this->mapStorage->delete($oldKey);
                    $io->writeln(sprintf('    → deleted %s', $oldKey));
                }
            }

            $savedBytes += max(0, $beforeBytes - \strlen($newBytes));
            ++$touched;
        }

        if (!$dryRun && $updatedRecords > 0) {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%d processed, %d skipped, %d missing. %d Map rows migrated. Saved %s on the new files.',
            $touched,
            $skipped,
            $missing,
            $updatedRecords,
            $this->humanBytes($savedBytes),
        ));

        return Command::SUCCESS;
    }

    /**
     * Rewire every Map row whose imageUrl matches $oldUrl to $newUrl. Returns
     * the row count touched. The EntityManager flush happens once at the end
     * of the command so a partial run doesn't leave the DB in a half-migrated
     * state.
     */
    private function migrateMapRecords(string $oldUrl, string $newUrl): int
    {
        $migrated = 0;
        foreach ($this->mapRepository->findBy(['imageUrl' => $oldUrl]) as $map) {
            $map->setImageUrl($newUrl);
            ++$migrated;
        }

        return $migrated;
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function dimensions(string $bytes): ?array
    {
        $info = @getimagesizefromstring($bytes);
        if (false === $info) {
            return null;
        }

        return [(int) $info[0], (int) $info[1]];
    }

    private function encode(string $bytes, int $newW, int $newH, string $ext, int $quality): ?string
    {
        $src = @imagecreatefromstring($bytes);
        if (false === $src) {
            return null;
        }
        try {
            $dst = imagecreatetruecolor($newW, $newH);
            if (false === $dst) {
                return null;
            }
            try {
                // Preserve PNG/WebP transparency.
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
                if (false !== $transparent) {
                    imagefilledrectangle($dst, 0, 0, $newW, $newH, $transparent);
                }
                imagealphablending($dst, true);

                imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, imagesx($src), imagesy($src));
                ob_start();
                $ok = match ($ext) {
                    'png' => imagepng($dst, null, 6),
                    'gif' => imagegif($dst),
                    'webp' => imagewebp($dst, null, $quality),
                    default => imagejpeg($dst, null, $quality),
                };
                $out = ob_get_clean();
                if (false === $ok || false === $out) {
                    return null;
                }

                return $out;
            } finally {
                imagedestroy($dst);
            }
        } finally {
            imagedestroy($src);
        }
    }

    private function extFromKey(string $key): string
    {
        return strtolower(pathinfo($key, PATHINFO_EXTENSION)) ?: 'bin';
    }

    private function mimeForExt(string $ext): string
    {
        return match ($ext) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return sprintf('%.1f kB', $bytes / 1024);
        }

        return sprintf('%.1f MB', $bytes / (1024 * 1024));
    }
}
