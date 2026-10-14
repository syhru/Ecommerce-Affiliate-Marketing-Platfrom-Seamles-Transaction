<?php

declare(strict_types=1);

use Tests\PostgreSQL\Support\Ownership;

// The launcher must create ownership before loading the guarded PHPUnit bootstrap.
define('TDR_PG_SAFETY_PROBE', true);
require __DIR__.'/Support/Ownership.php';

$manifest = null;
$exit = 1;
try {
    $filter = null;
    $ci = false;
    for ($i = 1; $i < count($argv); $i++) {
        if ($argv[$i] === '--filter' && isset($argv[$i + 1]) && $filter === null) {
            $filter = $argv[++$i];
        } elseif (str_starts_with($argv[$i], '--filter=') && $filter === null) {
            $filter = substr($argv[$i], 9);
        } elseif ($argv[$i] === '--ci-owned-service' && !$ci) {
            $ci = true;
        } else {
            throw new RuntimeException('PG safety: unsupported-launcher-option');
        }
    }
    $manifest = $ci ? Ownership::createCi() : Ownership::createLocal();
    $command = [PHP_BINARY, dirname(__DIR__, 2).'/vendor/bin/phpunit', '-c', dirname(__DIR__, 2).'/phpunit.pgsql.xml', '--colors=never', '--do-not-cache-result', '--fail-on-phpunit-deprecation'];
    $env = Ownership::environment();
    // Negative safety tests always precede migration/service tests, even for --filter S3.
    // The cleanup-failure visibility proof is part of the same mandatory negative gate.
    $safety = Ownership::command([...$command, '--filter', 'SafetyTest|CleanupFailureVisibilityTest'], $env, 90);
    $redact = static fn (string $output): string => str_replace([$manifest['password'], $manifest['token']], '[redacted]', $output);
    fwrite(STDOUT, $redact($safety['stdout']));
    fwrite(STDERR, $redact($safety['stderr']));
    if ($safety['exit'] !== 0) {
        throw new RuntimeException('PG safety: negative-tests-failed');
    }
    $selected = [...$command, '--filter', $filter ?? 'InvariantConcurrencyTest'];
    $result = Ownership::command($selected, $env, 480);
    fwrite(STDOUT, $redact($result['stdout']));
    fwrite(STDERR, $redact($result['stderr']));
    $exit = $result['exit'] === 0 ? 0 : 1;
} catch (Throwable $e) {
    // Never print an exception message from PDO, Docker or application service execution.
    $reason = preg_match('/^PG safety: [a-z-]+$/D', $e->getMessage()) ? $e->getMessage() : get_class($e);
    fwrite(STDERR, 'PG launcher failed ('.$reason.").\n");
} finally {
    if ($manifest !== null) {
        try {
            Ownership::cleanup($manifest);
            fwrite(STDOUT, "Owned PostgreSQL cleanup complete.\n");
        } catch (Throwable $e) {
            fwrite(STDERR, 'Owned PostgreSQL cleanup failed; exact container '.$manifest['container_id']." requires ownership-verified recovery.\n");
            $exit = 1;
        }
    }
}
exit($exit);
