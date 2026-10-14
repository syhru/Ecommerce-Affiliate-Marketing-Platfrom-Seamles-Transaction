<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\Support;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PDO;
use RuntimeException;
use Throwable;

final class Ownership
{
    public const LABEL = 'tdr.ws07b.run';
    public const CI_LABEL = 'tdr.ws07b.ci';
    public const CI_CREDENTIAL = 'tdr_ws07b';
    private const CI_KEYS = ['GITHUB_ACTIONS', 'RUNNER_ENVIRONMENT', 'GITHUB_JOB', 'GITHUB_RUN_ID', 'GITHUB_RUN_ATTEMPT', 'GITHUB_REPOSITORY', 'TDR_PG_CI_SERVICE_ID', 'TDR_PG_CI_SERVICE_PORT'];
    public const CONNECTION = 'pgsql';
    public const APP_KEY = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    public static function preflight(array $env): void
    {
        foreach (['DATABASE_URL', 'DB_URL'] as $key) {
            self::guard(empty($env[$key]), 'inherited-url');
        }
        self::guard(PHP_VERSION_ID >= 80300, 'php-version');
        self::guard(extension_loaded('pdo_pgsql'), 'pdo-pgsql-unavailable');
        self::guard(function_exists('proc_open'), 'proc-open-unavailable');
    }

    public static function manifest(): array
    {
        return self::validate(self::currentEnvironment());
    }

    public static function validate(array $env): array
    {
        self::preflight($env);
        $optIn = $env['TDR_PG_OPT_IN'] ?? '';
        self::guard(in_array($optIn, ['owned-local-v1', 'owned-ci-v1'], true), 'missing-opt-in');
        $path = $env['TDR_PG_MANIFEST'] ?? '';
        self::guard(is_string($path) && is_file($path) && !is_link($path), 'missing-manifest');
        self::guard(filesize($path) <= 16384, 'manifest-size');
        try {
            $m = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('PG safety: invalid-manifest');
        }
        self::guard(is_array($m), 'invalid-manifest');
        foreach (['run_id', 'container_id', 'container_name', 'image_id', 'database', 'username', 'password', 'token', 'scratch', 'port', 'created_at'] as $key) {
            self::guard(isset($m[$key]), 'manifest-field');
        }
        self::guard(preg_match('/^[a-f0-9]{32}$/D', (string) $m['run_id']) === 1, 'run-id');
        self::guard(preg_match('/^[a-f0-9]{64}$/D', (string) $m['container_id']) === 1, 'container-id');
        self::guard(preg_match('/^sha256:[a-f0-9]{64}$/D', (string) $m['image_id']) === 1, 'image-id');
        $ci = ($m['mode'] ?? 'local') === 'ci';
        self::guard(in_array($m['mode'] ?? 'local', ['local', 'ci'], true) && $optIn === ($ci ? 'owned-ci-v1' : 'owned-local-v1'), 'manifest-mode');
        if ($ci) {
            self::guard(preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/D', (string) $m['container_name']) === 1, 'container-name');
            self::guard($m['database'] === self::CI_CREDENTIAL && $m['username'] === self::CI_CREDENTIAL && $m['password'] === self::CI_CREDENTIAL, 'database-identity');
            self::guard(($m['ci_provenance'] ?? null) === self::ciProvenance($env), 'ci-provenance');
            self::guard($m['container_id'] === $env['TDR_PG_CI_SERVICE_ID'] && (string) $m['port'] === $env['TDR_PG_CI_SERVICE_PORT'], 'ci-service-identity');
        } else {
            self::guard($m['container_name'] === 'tdr-ws07b-'.$m['run_id'], 'container-name');
            self::guard($m['database'] === 'tdr_'.$m['run_id'] && $m['username'] === 'tdr_'.$m['run_id'], 'database-identity');
            self::guard(preg_match('/^[a-f0-9]{64}$/D', (string) $m['password']) === 1, 'manifest-secret-shape');
        }
        self::guard(preg_match('/^[a-f0-9]{64}$/D', (string) $m['token']) === 1, 'manifest-secret-shape');
        self::guard(is_int($m['port']) && $m['port'] > 1024 && $m['port'] <= 65535 && $m['port'] !== 5432, 'port');
        self::guard(is_int($m['created_at']) && $m['created_at'] <= time() && time() - $m['created_at'] < 1800, 'expired-manifest');
        $scratch = realpath(sys_get_temp_dir().DIRECTORY_SEPARATOR.'tdr-ws07b-'.$m['run_id']);
        self::guard($scratch !== false && realpath((string) $m['scratch']) === $scratch && !is_link($m['scratch']), 'scratch-identity');
        self::guard(realpath($path) === $scratch.DIRECTORY_SEPARATOR.'manifest.json', 'manifest-location');
        self::guard(!str_starts_with(strtolower(str_replace('\\', '/', $scratch)), strtolower(str_replace('\\', '/', dirname(__DIR__, 4))).'/'), 'scratch-in-repository');
        self::guard(($env['DB_CONNECTION'] ?? self::CONNECTION) === self::CONNECTION, 'wrong-default');
        foreach ($env as $key => $value) {
            if ((str_starts_with($key, 'DB_') && !in_array($key, ['DB_CONNECTION', 'DB_URL'], true)) || str_starts_with($key, 'PG')) {
                self::guard($value === '' || $value === false, 'inherited-database-setting');
            }
        }
        $cache = $scratch.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'config.php';
        self::guard(empty($env['APP_CONFIG_CACHE']) || $env['APP_CONFIG_CACHE'] === $cache, 'cached-config-path');
        self::guard(!file_exists($cache), 'cached-config');
        self::inspect($m);
        self::verifySentinel(self::pdo($m), $m);
        return $m;
    }

    private static function inspect(array $m, bool $cleanup = false): void
    {
        $result = self::command(['docker', 'inspect', '--type=container', $m['container_id']], self::systemEnvironment(), 10);
        self::guard($result['exit'] === 0, 'container-inspect');
        try {
            $items = json_decode($result['stdout'], true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('PG safety: container-inspect-format');
        }
        self::guard(is_array($items) && count($items) === 1, 'container-inspect-count');
        $c = $items[0];
        if (($m['mode'] ?? 'local') === 'ci') {
            self::inspectCi($c, $m);
            return;
        }
        self::guard(($c['Id'] ?? '') === $m['container_id'] && ($c['Name'] ?? '') === '/'.$m['container_name'], 'container-identity');
        self::guard(($c['Config']['Labels'][self::LABEL] ?? '') === $m['run_id'], 'container-label');
        self::guard(($c['Config']['Image'] ?? '') === 'postgres:18' && ($c['Image'] ?? '') === $m['image_id'], 'container-image');
        self::guard($cleanup || ($c['State']['Running'] ?? false) === true, 'container-not-running');
        self::guard(empty($c['HostConfig']['Binds']) && empty($c['HostConfig']['VolumesFrom']) && empty($c['HostConfig']['Privileged']), 'container-host-mount');
        // postgres:18 declares a VOLUME. An explicit private tmpfs prevents an anonymous reusable volume.
        $mounts = $c['Mounts'] ?? [];
        self::guard(empty($mounts) && ($c['HostConfig']['Tmpfs'] ?? []) === ['/var/lib/postgresql' => 'rw'], 'container-storage');
        if ($cleanup) {
            return;
        }
        $bindings = $c['NetworkSettings']['Ports'] ?? [];
        self::guard(count($bindings) === 1 && isset($bindings['5432/tcp']) && count($bindings['5432/tcp']) === 1, 'container-ports');
        $binding = $bindings['5432/tcp'][0];
        self::guard(($binding['HostIp'] ?? '') === '127.0.0.1' && (int) ($binding['HostPort'] ?? 0) === $m['port'], 'container-endpoint');
        $configured = $c['HostConfig']['PortBindings'] ?? [];
        self::guard(count($configured) === 1 && count($configured['5432/tcp'] ?? []) === 1 && ($configured['5432/tcp'][0]['HostIp'] ?? '') === '127.0.0.1', 'container-binding');
        foreach (['POSTGRES_DB' => $m['database'], 'POSTGRES_USER' => $m['username'], 'POSTGRES_PASSWORD' => $m['password']] as $key => $value) {
            self::guard(in_array($key.'='.$value, $c['Config']['Env'] ?? [], true), 'container-database-settings');
        }
    }

    private static function ciProvenance(array $env): array
    {
        self::guard(($env['GITHUB_ACTIONS'] ?? '') === 'true' && ($env['RUNNER_ENVIRONMENT'] ?? '') === 'github-hosted' && ($env['GITHUB_JOB'] ?? '') === 'postgresql-invariants', 'ci-job-identity');
        foreach (['GITHUB_RUN_ID', 'GITHUB_RUN_ATTEMPT'] as $key) {
            self::guard(is_string($env[$key] ?? null) && preg_match('/^[1-9][0-9]*$/D', $env[$key]) === 1, 'ci-run-identity');
        }
        self::guard(is_string($env['GITHUB_REPOSITORY'] ?? null) && preg_match('/^[a-zA-Z0-9_.-]+\/[a-zA-Z0-9_.-]+$/D', $env['GITHUB_REPOSITORY']) === 1, 'ci-repository-identity');
        self::guard(is_string($env['TDR_PG_CI_SERVICE_ID'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $env['TDR_PG_CI_SERVICE_ID']) === 1, 'ci-service-id');
        $port = $env['TDR_PG_CI_SERVICE_PORT'] ?? '';
        self::guard(is_string($port) && preg_match('/^[1-9][0-9]{3,4}$/D', $port) === 1 && (int) $port > 1024 && (int) $port <= 65535 && (int) $port !== 5432, 'ci-service-port');
        $provenance = [];
        foreach (self::CI_KEYS as $key) {
            $provenance[$key] = $env[$key];
        }
        return $provenance;
    }

    private static function inspectCi(array $c, array $m): void
    {
        $provenance = self::ciProvenance(self::currentEnvironment());
        self::guard(($m['ci_provenance'] ?? null) === $provenance && $m['container_id'] === $provenance['TDR_PG_CI_SERVICE_ID'] && (string) $m['port'] === $provenance['TDR_PG_CI_SERVICE_PORT'], 'ci-service-identity');
        self::guard(($c['Id'] ?? '') === $m['container_id'] && ($c['Name'] ?? '') === '/'.$m['container_name'], 'container-identity');
        self::guard(($c['Config']['Labels'][self::CI_LABEL] ?? '') === 'github-job-v1', 'container-label');
        self::guard(($c['Config']['Image'] ?? '') === 'postgres:18' && ($c['Image'] ?? '') === $m['image_id'], 'container-image');
        self::guard(($c['State']['Running'] ?? false) === true && ($c['State']['Health']['Status'] ?? '') === 'healthy', 'container-not-ready');
        self::guard(empty($c['HostConfig']['Binds']) && empty($c['HostConfig']['VolumesFrom']) && empty($c['HostConfig']['Privileged']) && empty($c['HostConfig']['Mounts']) && empty($c['HostConfig']['Tmpfs']), 'container-host-mount');
        $mounts = $c['Mounts'] ?? [];
        self::guard(count($mounts) === 1 && ($mounts[0]['Type'] ?? '') === 'volume' && ($mounts[0]['Destination'] ?? '') === '/var/lib/postgresql' && ($mounts[0]['RW'] ?? false) === true && preg_match('/^[a-f0-9]{64}$/D', $mounts[0]['Name'] ?? '') === 1 && ($c['Config']['Volumes'] ?? []) === ['/var/lib/postgresql' => []], 'container-storage');
        $ports = $c['NetworkSettings']['Ports'] ?? [];
        self::guard(count($ports) === 1 && !empty($ports['5432/tcp']) && count($ports['5432/tcp']) <= 2, 'container-ports');
        foreach ($ports['5432/tcp'] as $binding) {
            self::guard(in_array($binding['HostIp'] ?? '', ['0.0.0.0', '127.0.0.1', '::'], true) && (string) ($binding['HostPort'] ?? '') === (string) $m['port'], 'container-endpoint');
        }
        $configured = $c['HostConfig']['PortBindings'] ?? [];
        self::guard(count($configured) === 1 && count($configured['5432/tcp'] ?? []) === 1 && in_array($configured['5432/tcp'][0]['HostIp'] ?? '', ['', '0.0.0.0', '127.0.0.1'], true), 'container-binding');
        foreach (['POSTGRES_DB', 'POSTGRES_USER', 'POSTGRES_PASSWORD'] as $key) {
            self::guard(in_array($key.'='.self::CI_CREDENTIAL, $c['Config']['Env'] ?? [], true), 'container-database-settings');
        }
    }

    public static function environment(array $extra = []): array
    {
        $m = self::manifest();
        $s = $m['scratch'];
        $env = self::systemEnvironment() + [
            'TDR_PG_OPT_IN' => ($m['mode'] ?? 'local') === 'ci' ? 'owned-ci-v1' : 'owned-local-v1', 'TDR_PG_MANIFEST' => $s.DIRECTORY_SEPARATOR.'manifest.json',
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => self::APP_KEY,
            'DB_CONNECTION' => self::CONNECTION, 'DB_URL' => '', 'DATABASE_URL' => '',
            'APP_CONFIG_CACHE' => $s.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'config.php',
            'APP_SERVICES_CACHE' => $s.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'services.php',
            'APP_PACKAGES_CACHE' => $s.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'packages.php',
            'APP_ROUTES_CACHE' => $s.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'routes.php',
            'APP_EVENTS_CACHE' => $s.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'events.php',
            'APP_MAINTENANCE_DRIVER' => 'file', 'BCRYPT_ROUNDS' => '4', 'BROADCAST_CONNECTION' => 'null',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'LOG_CHANNEL' => 'null', 'MIDTRANS_SERVER_KEY' => '', 'MIDTRANS_CLIENT_KEY' => '', 'MIDTRANS_IS_PRODUCTION' => 'false',
            'TELEGRAM_BOT_TOKEN' => '', 'TELEGRAM_CHAT_ID' => '', 'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
        ];
        if (($m['mode'] ?? 'local') === 'ci') {
            $env += $m['ci_provenance'];
        }
        foreach ($extra as $key => $value) {
            self::guard(is_string($key) && str_starts_with($key, 'TDR_PG_') && !array_key_exists($key, $env) && is_string($value), 'unsafe-worker-environment');
            $env[$key] = $value;
        }
        return $env;
    }

    public static function currentEnvironment(): array
    {
        return array_merge(getenv(), $_ENV, $_SERVER);
    }

    private static function systemEnvironment(): array
    {
        $env = [];
        foreach (['PATH', 'Path', 'SystemRoot', 'SYSTEMROOT', 'WINDIR', 'COMSPEC', 'PATHEXT', 'TEMP', 'TMP', 'TMPDIR', 'HOME', 'USERPROFILE', 'LOCALAPPDATA'] as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $env[$key] = $value;
            }
        }
        return $env;
    }

    private static function installEnvironment(array $env): void
    {
        foreach (array_keys(self::currentEnvironment()) as $key) {
            if (is_string($key)) {
                putenv($key);
            }
        }
        $runtime = array_intersect_key($_SERVER, array_flip(['PHP_SELF', 'SCRIPT_NAME', 'SCRIPT_FILENAME', 'argc', 'argv', 'REQUEST_TIME', 'REQUEST_TIME_FLOAT']));
        $_ENV = [];
        $_SERVER = $runtime;
        foreach ($env as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }

    public static function boot(): Application
    {
        $existing = \Illuminate\Container\Container::getInstance();
        self::guard(!$existing->bound('db') || !$existing->resolved('db') || count($existing->make('db')->getConnections()) === 0, 'database-already-open');
        $m = self::manifest();
        self::installEnvironment(self::environment());
        $pdo = self::pdo($m);
        self::verifySentinel($pdo, $m);
        $pdo = null;
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->addAbsoluteCachePathPrefix(substr($m['scratch'], 0, 3));
        $app->useEnvironmentPath($m['scratch'].'/env');
        $app->loadEnvironmentFrom('absent.env');
        $app->useStoragePath($m['scratch'].'/storage');
        $app->afterBootstrapping(LoadConfiguration::class, static function (Application $app) use ($m): void {
            self::guard(!$app->configurationIsCached(), 'cached-config');
            self::guard(!$app->resolved('db'), 'database-resolved-before-validation');
            $app['config']->set('database', ['default' => self::CONNECTION, 'connections' => [self::CONNECTION => self::connection($m)], 'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true]]);
            $app['config']->set('activitylog.database_connection', null);
            $app['config']->set('services', ['midtrans' => ['server_key' => '', 'client_key' => '', 'is_production' => false], 'telegram' => ['bot_token' => '', 'chat_id' => '']]);
            $app['config']->set('mail.default', 'array');
            $app['config']->set('cache.default', 'array');
            $app['config']->set('session.driver', 'array');
            $app['config']->set('queue.default', 'sync');
            $app['config']->set('logging.default', 'null');
            $app['config']->set('view.compiled', $m['scratch'].'/storage/framework/views');
            $app->instance(\App\Services\MidtransService::class, \Mockery::mock(\App\Services\MidtransService::class));
        });
        $app->afterBootstrapping(RegisterProviders::class, static function (): void {
            Queue::fake();
            Notification::fake();
            Http::fake();
            Http::preventStrayRequests();
        });
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $db = $app['db'];
        self::guard($db->getDefaultConnection() === self::CONNECTION, 'wrong-default');
        self::verifySentinel($db->connection()->getPdo(), $m);
        return $app;
    }

    private static function connection(array $m): array
    {
        return ['driver' => 'pgsql', 'url' => null, 'host' => '127.0.0.1', 'port' => $m['port'], 'database' => $m['database'], 'username' => $m['username'], 'password' => $m['password'], 'charset' => 'utf8', 'prefix' => '', 'prefix_indexes' => true, 'search_path' => 'public', 'sslmode' => 'disable', 'options' => [PDO::ATTR_PERSISTENT => false], 'application_name' => 'tdr-ws07b-'.$m['run_id']];
    }

    private static function pdo(array $m): PDO
    {
        try {
            return new PDO('pgsql:host=127.0.0.1;port='.$m['port'].';dbname='.$m['database'].';connect_timeout=3;sslmode=disable', $m['username'], $m['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_PERSISTENT => false]);
        } catch (Throwable) {
            throw new RuntimeException('PG safety: owned-connection-failed');
        }
    }

    public static function verifySentinel(PDO $pdo, array $m): void
    {
        try {
            $row = $pdo->query("SELECT current_database() AS db, current_user AS usr, current_setting('server_version_num')::int AS version, current_setting('transaction_isolation') AS isolation, current_setting('transaction_read_only') AS readonly, pg_is_in_recovery() AS recovery")->fetch(PDO::FETCH_ASSOC);
            self::guard($row['db'] === $m['database'] && $row['usr'] === $m['username'], 'server-database-identity');
            self::guard((int) $row['version'] >= 180000 && (int) $row['version'] < 190000, 'server-major');
            self::guard($row['isolation'] === 'read committed' && $row['readonly'] === 'off' && !$row['recovery'], 'session-isolation-readwrite');
            $rows = $pdo->query('SELECT run_id, token FROM public.tdr_ws07b_ownership')->fetchAll(PDO::FETCH_ASSOC);
            self::guard(count($rows) === 1 && $rows[0]['run_id'] === $m['run_id'] && hash_equals($m['token'], $rows[0]['token']), 'sentinel');
            $pdo->exec("SET lock_timeout = '15s'; SET statement_timeout = '20s'; SET idle_in_transaction_session_timeout = '30s'");
        } catch (Throwable) {
            throw new RuntimeException('PG safety: sentinel-verification-failed');
        }
    }

    /** Only the launcher creates ownership; no caller-supplied endpoint or credentials. */
    public static function createLocal(): array
    {
        $original = self::currentEnvironment();
        self::preflight($original);
        self::guard(empty($original['APP_CONFIG_CACHE']), 'inherited-config-cache');
        $id = bin2hex(random_bytes(16));
        $s = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tdr-ws07b-'.$id;
        self::guard(mkdir($s, 0700), 'scratch-create');
        foreach (['env', 'cache', 'storage/logs', 'storage/framework/views', 'storage/framework/sessions', 'storage/framework/cache'] as $dir) {
            self::guard(mkdir($s.'/'.$dir, 0700, true), 'scratch-directory');
        }
        $m = ['run_id' => $id, 'container_name' => 'tdr-ws07b-'.$id, 'database' => 'tdr_'.$id, 'username' => 'tdr_'.$id, 'password' => bin2hex(random_bytes(32)), 'token' => bin2hex(random_bytes(32)), 'scratch' => realpath($s), 'created_at' => time()];
        try {
        $image = self::command(['docker', 'image', 'inspect', 'postgres:18', '--format', '{{.Id}}'], self::systemEnvironment(), 10);
        self::guard($image['exit'] === 0, 'postgres-image-not-installed');
        $m['image_id'] = trim($image['stdout']);
        $env = self::systemEnvironment() + ['POSTGRES_DB' => $m['database'], 'POSTGRES_USER' => $m['username'], 'POSTGRES_PASSWORD' => $m['password']];
        $run = self::command(['docker', 'run', '--detach', '--pull=never', '--name', $m['container_name'], '--label', self::LABEL.'='.$id, '--publish', '127.0.0.1::5432', '--tmpfs', '/var/lib/postgresql:rw', '--env', 'POSTGRES_DB', '--env', 'POSTGRES_USER', '--env', 'POSTGRES_PASSWORD', 'postgres:18'], $env, 30);
        self::guard($run['exit'] === 0 && preg_match('/^[a-f0-9]{64}$/D', trim($run['stdout'])) === 1, 'container-create');
        $m['container_id'] = trim($run['stdout']);
            $inspect = self::command(['docker', 'inspect', '--type=container', $m['container_id']], self::systemEnvironment(), 10);
            self::guard($inspect['exit'] === 0, 'container-inspect');
            $c = json_decode($inspect['stdout'], true, 64, JSON_THROW_ON_ERROR)[0];
            $m['port'] = (int) ($c['NetworkSettings']['Ports']['5432/tcp'][0]['HostPort'] ?? 0);
            self::inspect($m);
            $deadline = microtime(true) + 20;
            do {
                try {
                    $pdo = self::pdo($m);
                    break;
                } catch (RuntimeException) {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('PG safety: database-ready-timeout');
                    }
                    usleep(100000);
                }
            } while (true);
            self::guard((int) $pdo->query("SELECT count(*) FROM pg_tables WHERE schemaname = 'public'")->fetchColumn() === 0, 'database-not-empty');
            $pdo->exec('CREATE TABLE public.tdr_ws07b_ownership (run_id text PRIMARY KEY, token text NOT NULL)');
            $stmt = $pdo->prepare('INSERT INTO public.tdr_ws07b_ownership (run_id, token) VALUES (?, ?)');
            $stmt->execute([$id, $m['token']]);
            self::verifySentinel($pdo, $m);
            $pdo = null;
            self::guard(file_put_contents($s.'/manifest.json', json_encode($m, JSON_THROW_ON_ERROR), LOCK_EX) !== false, 'manifest-write');
            chmod($s.'/manifest.json', 0600);
            putenv('TDR_PG_OPT_IN=owned-local-v1');
            putenv('TDR_PG_MANIFEST='.$s.DIRECTORY_SEPARATOR.'manifest.json');
            return self::manifest();
        } catch (Throwable $e) {
            try {
                self::cleanup($m);
            } catch (Throwable) {
                fwrite(STDERR, 'PG safety: cleanup-failed; exact resource '.($m['container_id'] ?? $m['container_name'])." requires ownership-verified recovery.\n");
                throw new RuntimeException('PG safety: owner-cleanup-failed');
            }
            throw new RuntimeException(preg_match('/^PG safety: [a-z-]+$/D', $e->getMessage()) ? $e->getMessage() : 'PG safety: owner-initialization-failed');
        }
    }

    public static function createCi(): array
    {
        $original = self::currentEnvironment();
        self::preflight($original);
        self::guard(empty($original['APP_CONFIG_CACHE']), 'inherited-config-cache');
        $provenance = self::ciProvenance($original);
        $inspect = self::command(['docker', 'inspect', '--type=container', $provenance['TDR_PG_CI_SERVICE_ID']], self::systemEnvironment(), 10);
        self::guard($inspect['exit'] === 0, 'container-inspect');
        try {
            $items = json_decode($inspect['stdout'], true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('PG safety: container-inspect-format');
        }
        self::guard(is_array($items) && count($items) === 1, 'container-inspect-count');
        $c = $items[0];
        $id = bin2hex(random_bytes(16));
        $s = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tdr-ws07b-'.$id;
        $m = ['mode' => 'ci', 'run_id' => $id, 'container_id' => $provenance['TDR_PG_CI_SERVICE_ID'], 'container_name' => ltrim($c['Name'] ?? '', '/'), 'image_id' => $c['Image'] ?? '', 'database' => self::CI_CREDENTIAL, 'username' => self::CI_CREDENTIAL, 'password' => self::CI_CREDENTIAL, 'port' => (int) $provenance['TDR_PG_CI_SERVICE_PORT'], 'token' => bin2hex(random_bytes(32)), 'scratch' => $s, 'created_at' => time(), 'ci_provenance' => $provenance];
        self::inspectCi($c, $m);
        self::guard(mkdir($s, 0700), 'scratch-create');
        try {
            foreach (['env', 'cache', 'storage/logs', 'storage/framework/views', 'storage/framework/sessions', 'storage/framework/cache'] as $dir) {
                self::guard(mkdir($s.'/'.$dir, 0700, true), 'scratch-directory');
            }
            $m['scratch'] = realpath($s);
            $pdo = self::pdo($m);
            self::guard((int) $pdo->query("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname NOT LIKE 'pg_toast%' AND n.nspname NOT LIKE 'pg_temp%'")->fetchColumn() === 0, 'database-not-empty');
            $pdo->exec('CREATE TABLE public.tdr_ws07b_ownership (run_id text PRIMARY KEY, token text NOT NULL)');
            $stmt = $pdo->prepare('INSERT INTO public.tdr_ws07b_ownership (run_id, token) VALUES (?, ?)');
            $stmt->execute([$id, $m['token']]);
            self::verifySentinel($pdo, $m);
            $pdo = null;
            self::guard(file_put_contents($s.'/manifest.json', json_encode($m, JSON_THROW_ON_ERROR), LOCK_EX) !== false, 'manifest-write');
            chmod($s.'/manifest.json', 0600);
            self::installEnvironment(self::systemEnvironment() + $provenance + ['TDR_PG_OPT_IN' => 'owned-ci-v1', 'TDR_PG_MANIFEST' => $s.DIRECTORY_SEPARATOR.'manifest.json']);
            return self::manifest();
        } catch (Throwable $e) {
            self::cleanup($m);
            throw new RuntimeException(preg_match('/^PG safety: [a-z-]+$/D', $e->getMessage()) ? $e->getMessage() : 'PG safety: owner-initialization-failed');
        }
    }

    public static function cleanup(array $m): void
    {
        $mode = $m['mode'] ?? 'local';
        self::guard(in_array($mode, ['local', 'ci'], true), 'manifest-mode');
        self::guard(preg_match('/^[a-f0-9]{32}$/D', (string) ($m['run_id'] ?? '')) === 1, 'cleanup-run-id');
        $s = realpath($m['scratch']);
        self::guard($s !== false && $s === realpath(sys_get_temp_dir().DIRECTORY_SEPARATOR.'tdr-ws07b-'.$m['run_id']) && !is_link($m['scratch']), 'cleanup-scratch-identity');
        if (isset($m['container_id'])) {
            self::inspect($m, true);
            if ($mode === 'local') {
                $r = self::command(['docker', 'rm', '--force', $m['container_id']], self::systemEnvironment(), 15);
                self::guard($r['exit'] === 0, 'cleanup-container-failed');
            }
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($s, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            self::guard(!$entry->isLink(), 'cleanup-symlink');
            self::guard($entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()), 'cleanup-file-failed');
        }
        self::guard(rmdir($s), 'cleanup-directory-failed');
    }

    /** Bounded shell-free process execution. Output is returned privately, never included in errors. */
    public static function command(array $argv, array $env, int $seconds): array
    {
        self::guard(function_exists('proc_open'), 'proc-open-unavailable');
        $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3), $env, ['bypass_shell' => true]);
        self::guard(is_resource($process), 'process-start');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = $stderr = '';
        $deadline = microtime(true) + $seconds;
        $exit = -1;
        try {
            do {
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                self::guard(strlen($stdout) + strlen($stderr) <= 1048576, 'process-output-limit');
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exit = $status['exitcode'];
                    break;
                }
                self::guard(microtime(true) < $deadline, 'process-timeout');
                usleep(20000);
            } while (true);
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
        } finally {
            foreach ([15, 9] as $signal) {
                if (!proc_get_status($process)['running']) {
                    break;
                }
                proc_terminate($process, $signal);
                $stop = microtime(true) + 2;
                do {
                    usleep(25000);
                    $running = proc_get_status($process)['running'];
                } while ($running && microtime(true) < $stop);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::guard(!proc_get_status($process)['running'], 'process-cleanup-failed');
            $closed = proc_close($process);
            if ($exit < 0) {
                $exit = $closed;
            }
        }
        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private static function guard(bool $condition, string $reason): void
    {
        if (!$condition) {
            throw new RuntimeException('PG safety: '.$reason);
        }
    }
}

// PHPUnit uses this file as its autoload-only bootstrap; application boot is explicit in tests/workers.
require_once dirname(__DIR__, 3).'/vendor/autoload.php';

if (!defined('TDR_PG_SAFETY_PROBE')) {
    Ownership::manifest();
}
