<?php

declare(strict_types=1);

namespace Tests\PostgreSQL;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\PostgreSQL\Support\Ownership;

final class SafetyTest extends TestCase
{
    public static function invalidEnvironments(): array
    {
        return [
            'missing opt in' => [['TDR_PG_OPT_IN' => ''], 'missing-opt-in'],
            'missing manifest' => [['TDR_PG_MANIFEST' => ''], 'missing-manifest'],
            'bare DSN' => [['TDR_PG_OPT_IN' => '', 'DB_DSN' => 'pgsql:host=127.0.0.1;port=5432'], 'missing-opt-in'],
            'database URL' => [['DATABASE_URL' => 'pgsql://invalid.invalid/test'], 'inherited-url'],
            'DB URL' => [['DB_URL' => 'pgsql://invalid.invalid/test'], 'inherited-url'],
            'cached configuration' => [['APP_CONFIG_CACHE' => 'bootstrap/cache/config.php'], 'cached-config-path'],
            'wrong default' => [['DB_CONNECTION' => 'sqlite'], 'wrong-default'],
            'inherited database host' => [['DB_HOST' => '127.0.0.1'], 'inherited-database-setting'],
        ];
    }

    #[DataProvider('invalidEnvironments')]
    public function test_validation_rejects_before_application_boot(array $changes, string $reason): void
    {
        $before = \Illuminate\Container\Container::getInstance();
        try {
            Ownership::validate(array_replace(Ownership::environment(), $changes));
            self::fail('Unsafe environment was accepted');
        } catch (\RuntimeException $e) {
            self::assertSame('PG safety: '.$reason, $e->getMessage());
        }
        self::assertSame($before, \Illuminate\Container\Container::getInstance());
        self::assertFalse($before->resolved('db'));
    }

    public function test_forged_run_identity_is_rejected_without_application_boot(): void
    {
        $m = Ownership::manifest();
        $path = $m['scratch'].'/forged.json';
        $m['run_id'] = str_repeat('0', 32);
        // CI names the runner service; a forged local run ID hits container-name. In CI the forged run ID
        // first breaks the run-derived scratch path, so the mode-appropriate refusal differs.
        $expected = ($m['mode'] ?? 'local') === 'ci' ? 'scratch-identity' : 'container-name';
        file_put_contents($path, json_encode($m, JSON_THROW_ON_ERROR));
        try {
            $this->expectExceptionMessage('PG safety: '.$expected);
            Ownership::validate(array_replace(Ownership::environment(), ['TDR_PG_MANIFEST' => $path]));
        } finally {
            unlink($path);
        }
    }

    public function test_wrong_sentinel_is_rejected_without_application_boot(): void
    {
        $m = Ownership::manifest();
        $mock = $this->createMock(\PDO::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['db' => $m['database'], 'usr' => $m['username'], 'version' => 180000, 'isolation' => 'read committed', 'readonly' => 'off', 'recovery' => false]);
        $stmt->method('fetchAll')->willReturn([['run_id' => $m['run_id'], 'token' => str_repeat('0', 64)]]);
        $mock->method('query')->willReturn($stmt);
        $mock->expects(self::never())->method('exec');
        $this->expectExceptionMessage('PG safety: sentinel-verification-failed');
        Ownership::verifySentinel($mock, $m);
    }

    public function test_real_owned_sentinel_rejects_wrong_token_and_run_id(): void
    {
        $m = Ownership::manifest();
        $pdo = (new \ReflectionMethod(Ownership::class, 'pdo'))->invoke(null, $m);
        foreach (['token' => str_repeat('0', 64), 'run_id' => str_repeat('0', 32)] as $key => $value) {
            try {
                Ownership::verifySentinel($pdo, array_replace($m, [$key => $value]));
                self::fail('Wrong sentinel accepted');
            } catch (\RuntimeException $e) {
                self::assertSame('PG safety: sentinel-verification-failed', $e->getMessage());
            }
        }
        self::assertFalse(\Illuminate\Container\Container::getInstance()->resolved('db'));
    }

    public function test_actual_stale_cache_file_is_rejected_before_boot(): void
    {
        $m = Ownership::manifest();
        $env = Ownership::environment();
        $path = $env['APP_CONFIG_CACHE'];
        file_put_contents($path, '<?php throw new RuntimeException("Cache executed");');
        try {
            Ownership::validate($env);
            self::fail('Stale cache accepted');
        } catch (\RuntimeException $e) {
            self::assertSame('PG safety: cached-config', $e->getMessage());
        } finally {
            unlink($path);
        }
        self::assertFalse(\Illuminate\Container\Container::getInstance()->resolved('db'));
    }

    public function test_missing_pdo_extension_is_fail_closed_in_independent_process(): void
    {
        $code = "define('TDR_PG_SAFETY_PROBE', true); require ".var_export(__DIR__.'/Support/Ownership.php', true)."; try { Tests\\PostgreSQL\\Support\\Ownership::preflight([]); exit(9); } catch (RuntimeException \$e) { exit(\$e->getMessage() === 'PG safety: pdo-pgsql-unavailable' ? 0 : 8); }";
        self::assertSame(0, Ownership::command([PHP_BINARY, '-n', '-r', $code], Ownership::environment(), 10)['exit']);
    }

    public function test_missing_proc_open_is_fail_closed_in_independent_process(): void
    {
        $code = "define('TDR_PG_SAFETY_PROBE', true); require ".var_export(__DIR__.'/Support/Ownership.php', true)."; try { Tests\\PostgreSQL\\Support\\Ownership::preflight([]); exit(9); } catch (RuntimeException \$e) { exit(\$e->getMessage() === 'PG safety: proc-open-unavailable' ? 0 : 8); }";
        self::assertSame(0, Ownership::command([PHP_BINARY, '-d', 'disable_functions=proc_open', '-r', $code], Ownership::environment(), 10)['exit']);
    }
}
