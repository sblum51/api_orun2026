<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Event;
use App\Entity\Organization;
use App\Entity\User;
use App\Enum\EventType;
use App\Enum\Visibility;
use App\Repository\UserRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Imports data from the legacy `api-orun` (2022-era) Postgres database into
 * the new orun2026 schema.
 *
 * The legacy schema is read via raw DBAL SQL — its old API Platform v2
 * entities are NOT loaded, since they would conflict with the current bundle
 * versions. The new schema is written via the EntityManager + entities so all
 * lifecycle callbacks (timestamps, slug generation) fire normally.
 *
 * Mapping notes:
 * - legacy `user` (email, password, firstname, name) → `users`. Passwords are
 *   re-hashed if `--rehash-passwords` is set (otherwise copied as-is, which
 *   only works if the hashing algo hasn't changed).
 * - legacy `organization.created_by` and `managed_by` join → `organization_members`.
 * - legacy `event.creator_orga` → new `events.organization_id`.
 *   legacy `event.creator_user` → new `events.creator_id`.
 * - legacy `course.controls JSON` / `tag` rows → TODO (Control entity).
 * - legacy `activity` → TODO (needs Team/Punch model decisions).
 */
#[AsCommand(
    name: 'app:migrate:from-legacy',
    description: 'Import users / organizations / events / courses from the legacy api-orun database.',
)]
final class MigrateFromLegacyCommand extends Command
{
    private const ENTITY_TYPES = ['users', 'organizations', 'events', 'courses'];

    /** @var array<string, Uuid> Map of legacy UUID string → new Uuid for users. */
    private array $userMap = [];

    /** @var array<string, Uuid> Map of legacy UUID string → new Uuid for organizations. */
    private array $orgMap = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'legacy-dsn',
                null,
                InputOption::VALUE_REQUIRED,
                'DBAL DSN for the legacy database. Defaults to env LEGACY_DATABASE_URL.',
            )
            ->addOption(
                'only',
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated list of entity types to import. Allowed: '.implode(', ', self::ENTITY_TYPES),
                implode(',', self::ENTITY_TYPES),
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Inspect rows and report; do not write to the new DB.')
            ->addOption(
                'rehash-passwords',
                null,
                InputOption::VALUE_NONE,
                'Re-hash user passwords with the new hashing algorithm (slow). If absent, passwords are copied as-is.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string|null $dsn */
        $dsn = $input->getOption('legacy-dsn') ?: $_ENV['LEGACY_DATABASE_URL'] ?? null;
        if (null === $dsn) {
            $io->error('Pass --legacy-dsn=... or set LEGACY_DATABASE_URL in your environment.');

            return Command::FAILURE;
        }

        $legacy = DriverManager::getConnection(['url' => $dsn]);
        try {
            $legacy->connect();
        } catch (\Throwable $e) {
            $io->error('Cannot connect to legacy DB: '.$e->getMessage());

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $rehash = (bool) $input->getOption('rehash-passwords');
        $only = array_map('trim', explode(',', (string) $input->getOption('only')));
        $unknown = array_diff($only, self::ENTITY_TYPES);
        if ([] !== $unknown) {
            $io->error('Unknown --only types: '.implode(', ', $unknown));

            return Command::FAILURE;
        }

        $io->section('Legacy → orun2026 migration ('.($dryRun ? 'dry-run' : 'live').')');

        if (\in_array('users', $only, true)) {
            $this->importUsers($legacy, $io, $dryRun, $rehash);
        }
        if (\in_array('organizations', $only, true)) {
            $this->importOrganizations($legacy, $io, $dryRun);
        }
        if (\in_array('events', $only, true)) {
            $this->importEvents($legacy, $io, $dryRun);
        }
        if (\in_array('courses', $only, true)) {
            $io->writeln('  Courses import — TODO (legacy controls JSON needs mapping to Control entities).');
        }

        return Command::SUCCESS;
    }

    private function importUsers(\Doctrine\DBAL\Connection $legacy, SymfonyStyle $io, bool $dryRun, bool $rehash): void
    {
        $io->writeln('Importing users…');
        $rows = $legacy->fetchAllAssociative('SELECT id, email, password, firstname, name, roles FROM "user"');
        $created = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $email = (string) $row['email'];
            if ('' === $email) {
                ++$skipped;
                continue;
            }
            $existing = $this->userRepository->findOneBy(['email' => $email]);
            if (null !== $existing) {
                $this->userMap[(string) $row['id']] = $existing->getId();
                ++$skipped;
                continue;
            }

            $user = new User(
                $email,
                (string) ($row['firstname'] ?? ''),
                (string) ($row['name'] ?? ''),
            );
            $password = (string) $row['password'];
            if ($rehash) {
                // The plain-text password isn't available; re-hashing the
                // already-hashed string is the best we can do offline.
                // In practice, force-reset passwords via the forgot-password flow.
                $password = $this->passwordHasher->hashPassword($user, $password);
            }
            $user->setPassword($password);
            $roles = $this->decodeJson($row['roles']) ?: [];
            $user->setRoles(array_values(array_filter($roles, 'is_string')));

            if (!$dryRun) {
                $this->em->persist($user);
                $this->em->flush();
                $this->em->clear(User::class);
                $this->userMap[(string) $row['id']] = $user->getId();
            }
            ++$created;
        }
        $io->writeln(sprintf('  %d created, %d skipped.', $created, $skipped));
    }

    private function importOrganizations(\Doctrine\DBAL\Connection $legacy, SymfonyStyle $io, bool $dryRun): void
    {
        $io->writeln('Importing organizations…');
        $rows = $legacy->fetchAllAssociative('SELECT id, name, created_by_id FROM organization');
        $created = 0;
        foreach ($rows as $row) {
            $creatorLegacyId = (string) $row['created_by_id'];
            if (!isset($this->userMap[$creatorLegacyId])) {
                $io->warning(sprintf('Org %s: skipping, creator user %s not migrated.', $row['name'], $creatorLegacyId));
                continue;
            }
            $org = new Organization((string) $row['name']);
            if (!$dryRun) {
                $creator = $this->em->find(User::class, $this->userMap[$creatorLegacyId]);
                if (null === $creator) {
                    continue;
                }
                $org->addMember($creator);
                $this->em->persist($org);
                $this->em->flush();
                $this->orgMap[(string) $row['id']] = $org->getId();
            }
            ++$created;
        }
        $io->writeln(sprintf('  %d created.', $created));
    }

    private function importEvents(\Doctrine\DBAL\Connection $legacy, SymfonyStyle $io, bool $dryRun): void
    {
        $io->writeln('Importing events…');
        $rows = $legacy->fetchAllAssociative(<<<'SQL'
            SELECT id, name, start_date, end_date, visibility, type, creator_orga_id, creator_user_id
            FROM event
        SQL);
        $created = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $creatorLegacyId = (string) ($row['creator_user_id'] ?? '');
            if ('' === $creatorLegacyId || !isset($this->userMap[$creatorLegacyId])) {
                ++$skipped;
                continue;
            }

            $event = new Event(
                (string) $row['name'],
                $this->mapEventType((string) ($row['type'] ?? 'temporal')),
            );
            $event->setStartDate(null !== $row['start_date'] ? new \DateTimeImmutable((string) $row['start_date']) : null);
            $event->setEndDate(null !== $row['end_date'] ? new \DateTimeImmutable((string) $row['end_date']) : null);
            $event->setVisibility('private' === ($row['visibility'] ?? null) ? Visibility::Private : Visibility::Public);

            if (!$dryRun) {
                $creator = $this->em->find(User::class, $this->userMap[$creatorLegacyId]);
                if (null === $creator) {
                    continue;
                }
                $event->setCreator($creator);

                $orgLegacyId = (string) ($row['creator_orga_id'] ?? '');
                if ('' !== $orgLegacyId && isset($this->orgMap[$orgLegacyId])) {
                    $org = $this->em->find(Organization::class, $this->orgMap[$orgLegacyId]);
                    if (null !== $org) {
                        $event->setOrganization($org);
                    }
                }

                $this->em->persist($event);
                $this->em->flush();
            }
            ++$created;
        }
        $io->writeln(sprintf('  %d created, %d skipped (missing creator).', $created, $skipped));
    }

    private function mapEventType(string $legacy): EventType
    {
        return match (strtolower($legacy)) {
            'permanent' => EventType::Permanent,
            'seasonal' => EventType::Seasonal,
            default => EventType::Temporal,
        };
    }

    /**
     * @return list<string>|null
     */
    private function decodeJson(mixed $raw): ?array
    {
        if (!is_string($raw) || '' === $raw) {
            return null;
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 5, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? array_values($decoded) : null;
    }
}
