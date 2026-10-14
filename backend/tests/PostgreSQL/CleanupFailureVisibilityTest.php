<?php

declare(strict_types=1);

namespace Tests\PostgreSQL;

use PHPUnit\Framework\TestCase;
use Tests\PostgreSQL\Support\Ownership;

final class CleanupFailureVisibilityTest extends TestCase
{
    public function test_launcher_reports_exact_resource_and_exits_nonzero_on_simulated_cleanup_failure(): void
    {
        $scratch = Ownership::manifest()['scratch'].'/cleanup-simulation';
        self::assertTrue(mkdir($scratch, 0700));
        $id = str_repeat('a', 64);
        $stub = <<<'PHP'
<?php
namespace Tests\PostgreSQL\Support;
final class Ownership {
    public static function createLocal(): array { return ['container_id' => str_repeat('a', 64), 'password' => 'simulation-password', 'token' => 'simulation-token']; }
    public static function environment(): array { return []; }
    public static function command(array $argv, array $env, int $seconds): array { return ['stdout' => '', 'stderr' => '', 'exit' => 0]; }
    public static function cleanup(array $manifest): void { throw new \RuntimeException('Controlled test-only cleanup failure'); }
}
PHP;
        try {
            file_put_contents($scratch.'/stub.php', $stub);
            $source = file_get_contents(__DIR__.'/run.php');
            $source = str_replace("require __DIR__.'/Support/Ownership.php';", 'require '.var_export($scratch.'/stub.php', true).';', $source, $count);
            self::assertSame(1, $count);
            file_put_contents($scratch.'/launcher.php', $source);
            $result = Ownership::command([PHP_BINARY, $scratch.'/launcher.php'], Ownership::environment(), 10);
            self::assertSame(1, $result['exit']);
            self::assertSame('Owned PostgreSQL cleanup failed; exact container '.$id." requires ownership-verified recovery.\n", str_replace("\r\n", "\n", $result['stderr']));
            self::assertStringNotContainsString('cleanup complete', $result['stdout']);
            fwrite(STDOUT, "Cleanup simulation: actual launcher exit=1; exact resource=$id; no Docker/database commands executed by stub.\n");
        } finally {
            foreach (['launcher.php', 'stub.php'] as $file) {
                if (is_file($scratch.'/'.$file)) {
                    unlink($scratch.'/'.$file);
                }
            }
            rmdir($scratch);
        }
    }
}
