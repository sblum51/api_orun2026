<?php

use Castor\Attribute\AsTask;

use function Castor\io;
use function Castor\run;
use function Castor\ssh_run;

// Cibles de déploiement (hôtes SSH du serveur partagé). 'nom' => 'user@host'.
const TARGETS = [
    'preprod' => 'deployer@preprod.api-v2.orun.app',
    'prod'    => 'deployer@api.notifs.cloud',
];

// Modèle de config runtime (.env serveur) déployé par `castor setup` selon l'environnement.
const ENV_FILES = [
    'preprod' => 'docker/server/.env.preprod',
    'prod'    => 'docker/server/.env.prod',
];

// Domaine public servi par le Caddy partagé (info, à mettre dans ~/docker/.env côté serveur).
const DOMAINS = [
    'preprod' => 'preprod.api-v2.orun.app',
    'prod'    => 'api-v2.orun.app',
];

const REPO = 'git@github.com:sblum51/api_orun2026.git';

// Branche par défaut suivie sur chaque cible. Surchargeable par `--branch=`
// sur n'importe quelle tâche (utile pour pousser une feature branch en
// preprod sans toucher main).
const BRANCHES = [
    'preprod' => 'main',
    'prod'    => 'main',
];

const APP_DIR   = '~/orun-api';
const IMAGE     = 'orun-api:latest';
const SERVICE   = 'orun-api';

/**
 * Résout l'hôte SSH à partir du nom de cible.
 */
function host(string $target): string
{
    if (!isset(TARGETS[$target])) {
        throw new \RuntimeException(sprintf(
            'Cible inconnue : "%s". Valeurs possibles : %s',
            $target,
            implode(', ', array_keys(TARGETS))
        ));
    }

    return TARGETS[$target];
}

/**
 * Branche Git à déployer. Priorité au paramètre CLI quand fourni
 * (ex. `castor deploy --target=preprod --branch=feat/foo`), sinon la
 * branche par défaut de la cible.
 */
function branch(string $target, string $branch = ''): string
{
    if ('' !== $branch) {
        return $branch;
    }

    return BRANCHES[$target] ?? 'main';
}

#[AsTask(description: 'Prépare un environnement sur le serveur (dirs + compose + modèle .env + JWT)')]
function setup(string $target = 'preprod'): void
{
    $h = host($target);
    io()->title(sprintf('Préparation → %s (%s)', $target, $h));

    if ($target === 'prod' && !io()->confirm('⚠ Préparer la PRODUCTION ?', false)) {
        io()->warning('Abandon.');
        return;
    }

    io()->section('Répertoires applicatifs');
    ssh_run('mkdir -p ' . APP_DIR . '/jwt ' . APP_DIR . '/uploads ' . APP_DIR . '/logs', $h);

    io()->section('Compose + modèle .env');
    run(['scp', 'compose.prod.yaml', $h . ':' . APP_DIR . '/compose.yaml']);
    $tpl = ENV_FILES[$target] ?? 'docker/server/.env.dist';
    // On dépose un modèle (.env.dist) sans écraser un .env existant (secrets serveur).
    run(['scp', $tpl, $h . ':' . APP_DIR . '/.env.dist']);

    io()->section('Clés JWT');
    if (is_file('config/jwt/private.pem') && is_file('config/jwt/public.pem')) {
        run(['scp', 'config/jwt/private.pem', 'config/jwt/public.pem', $h . ':' . APP_DIR . '/jwt/']);
        // FrankenPHP ≥ 1.4 runs as the `app` user (UID 33); `generate-keypair`
        // writes 0600 owned by the developer's UID, so the bind-mounted file
        // is unreadable inside the container. 0644 is safe — the directory
        // itself isn't web-exposed and the host user is the deployer.
        ssh_run('chmod 0644 ' . APP_DIR . '/jwt/private.pem ' . APP_DIR . '/jwt/public.pem', $h);
    } else {
        io()->warning('Clés JWT absentes en local — génère-les : php bin/console lexik:jwt:generate-keypair');
    }

    io()->success('Squelette en place sur ' . $h);
    io()->note([
        'À terminer sur le serveur :',
        '  cd ' . APP_DIR . ' && cp -n .env.dist .env',
        '  $EDITOR .env   # APP_SECRET, DATABASE_URL, JWT_PASSPHRASE, CORS_ALLOW_ORIGIN',
        'Infra partagée (~/docker) :',
        '  - Postgres en image postgis/postgis:17-alpine + base "orun_preprod"',
        '  - ORUN_API_DOMAIN=' . (DOMAINS[$target] ?? 'à définir') . ' dans ~/docker/.env',
        '  - bloc Caddyfile.snippet ajouté à ~/docker/Caddyfile, puis recharger Caddy',
        'Puis : castor deploy --target=' . $target,
    ]);
}

#[AsTask(description: 'Déploiement complet (--target=preprod par défaut, prod demande confirmation)')]
function deploy(string $target = 'preprod', string $branch = ''): void
{
    $b = branch($target, $branch);
    io()->title(sprintf('Déploiement → %s (%s) — branche %s', $target, host($target), $b));

    if ($target === 'prod' && !io()->confirm('⚠ Déploiement en PRODUCTION. Confirmer ?', false)) {
        io()->warning('Abandon.');
        return;
    }

    pull($target, $branch);
    build($target);
    up($target);
    migrate($target);
    check($target);
    prune($target);
}

#[AsTask(description: 'Git pull (clone + checkout de la branche; surcharge via --branch=)')]
function pull(string $target = 'preprod', string $branch = ''): void
{
    $b = branch($target, $branch);
    io()->section(sprintf('Git pull (%s)', $b));
    // Fetch puis hard reset sur origin/<branch>. Le `git checkout` -B garantit
    // qu'on est bien sur la bonne branche locale, même après un changement
    // de cible (preprod main → preprod feat/foo et inversement).
    ssh_run(
        'if [ -d ' . APP_DIR . '/repo/.git ]; then'
        . ' cd ' . APP_DIR . '/repo'
        . ' && git fetch origin'
        . ' && git checkout -B ' . escapeshellarg($b) . ' origin/' . escapeshellarg($b)
        . ' && git reset --hard origin/' . escapeshellarg($b) . ';'
        . ' else'
        . ' rm -rf ' . APP_DIR . '/repo'
        . ' && git clone --branch ' . escapeshellarg($b) . ' ' . REPO . ' ' . APP_DIR . '/repo;'
        . ' fi',
        host($target)
    );
}

#[AsTask(description: 'Build Docker image (Dockerfile multi-stage, target=prod)')]
function build(string $target = 'preprod'): void
{
    io()->section('Docker build');
    // If the deployer has a ~/.composer/auth.json on the server, pipe it
    // through as a build secret so composer can authenticate to GitHub and
    // avoid the unauthenticated codeload rate limit ("HTTP/2 400" errors).
    // The Dockerfile marks the secret as optional, so a missing file just
    // falls back to anonymous installs.
    ssh_run(
        'cd ' . APP_DIR . '/repo'
        . ' && SECRET_OPT=""'
        . ' && if [ -f "$HOME/.composer/auth.json" ]; then'
        . '      SECRET_OPT="--secret id=composer_auth,src=$HOME/.composer/auth.json";'
        . '    fi'
        . ' && DOCKER_BUILDKIT=1 docker build $SECRET_OPT'
        . '   -f docker/Dockerfile -t ' . IMAGE . ' --target prod .',
        host($target)
    );
}

#[AsTask(description: 'Restart containers (docker compose up -d --force-recreate)')]
function up(string $target = 'preprod'): void
{
    io()->section('Docker up');
    ssh_run(
        'cd ' . APP_DIR . ' && docker compose up -d --force-recreate',
        host($target)
    );
}

#[AsTask(description: 'Run database migrations')]
function migrate(string $target = 'preprod'): void
{
    io()->section('Migrations');
    ssh_run(
        'cd ' . APP_DIR . ' && docker compose exec -T ' . SERVICE
        . ' php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration',
        host($target)
    );
}

#[AsTask(description: 'Verify deployment (vendor + docker compose ps)')]
function check(string $target = 'preprod'): void
{
    io()->section('Check');
    ssh_run('docker run --rm ' . IMAGE . ' ls vendor/ | wc -l', host($target));
    ssh_run('cd ' . APP_DIR . ' && docker compose ps', host($target));
}

#[AsTask(description: 'Cleanup old Docker images')]
function prune(string $target = 'preprod'): void
{
    ssh_run('docker image prune -f --filter "dangling=true"', host($target));
}

#[AsTask(description: 'Tail logs')]
function logs(string $target = 'preprod'): void
{
    ssh_run(
        'cd ' . APP_DIR . ' && docker compose logs --tail 50 -f',
        host($target)
    );
}
