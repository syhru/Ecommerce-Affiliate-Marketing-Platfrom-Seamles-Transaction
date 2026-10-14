<?php

declare(strict_types=1);

namespace Tests\PostgreSQL;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\PostgreSQL\Support\Ownership;
use Tests\PostgreSQL\Support\ProcessHarness;

final class HarnessSafetyTest extends TestCase
{
    public static function faults(): array
    {
        return [
            'missing barrier while alive' => ['signal', 'sleep(60);', 'Missing worker A locked gate.'],
            'child crash before barrier' => ['signal', 'exit(23);', 'Worker A exited before gate/blocker observation.'],
            'nonzero exit despite plausible result' => ['finish', 'file_put_contents($dir."/A.result.json", $valid); exit(23);', 'Worker failed or result absent/oversized (exit 23).'],
            'missing result after zero exit' => ['finish', 'exit(0);', 'Worker failed or result absent/oversized (exit 0).'],
            'invalid structured result' => ['finish', 'file_put_contents($dir."/A.result.json", "{}"); exit(0);', 'Invalid worker result.'],
            'oversized result after zero exit' => ['finish', '$data = json_decode($valid, true); $data["padding"] = str_repeat("x", 4096); file_put_contents($dir."/A.result.json", json_encode($data)); exit(0);', 'Worker failed or result absent/oversized (exit 0).'],
            'result timeout despite plausible result' => ['finish', 'file_put_contents($dir."/A.result.json", $valid); sleep(60);', 'Worker result timeout.'],
        ];
    }

    #[DataProvider('faults')]
    public function test_fault_cannot_pass_and_child_is_reaped(string $method, string $fault, string $message): void
    {
        // These are protocol safety probes, not substitutes for the PostgreSQL race proofs.
        $manifest = Ownership::manifest();
        $directory = $manifest['scratch'].'/harness-negative-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $script = $directory.'/fault.php';
        $valid = json_encode(['scenario' => 'negative', 'worker' => 'A', 'phase' => 'finished', 'exit' => 0, 'outcome' => 'ok', 'backend_pid' => 1], JSON_THROW_ON_ERROR);
        file_put_contents($script, '<?php $dir = __DIR__; $valid = '.var_export($valid, true).'; '.$fault);
        $harness = new ProcessHarness;
        $start = hrtime(true);
        $pipes = [];
        $child = null;
        try {
            $child = proc_open([PHP_BINARY, $script], [0 => ['pipe', 'r'], 1 => ['file', $directory.'/A.stdout', 'w'], 2 => ['file', $directory.'/A.stderr', 'w']], $pipes, $directory, Ownership::environment(), ['bypass_shell' => true]);
            self::assertIsResource($child);
            fclose($pipes[0]);
            $this->set($harness, 'directory', $directory);
            $this->set($harness, 'children', ['A' => $child]);
            $this->set($harness, 'started', ['A' => hrtime(true)]);
            $this->set($harness, 'deadline', hrtime(true) + 40_000_000_000);
            try {
                (new ReflectionMethod($harness, $method))->invoke($harness, 'A', $method === 'signal' ? 'locked' : 'negative');
                self::fail('Controlled harness fault was accepted.');
            } catch (RuntimeException $e) {
                self::assertSame($message, $e->getMessage());
                if (str_contains($fault, 'padding')) {
                    $file = $directory.'/A.result.json';
                    self::assertGreaterThan(4096, filesize($file));
                    $data = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
                    unset($data['padding']);
                    self::assertSame(json_decode($valid, true), $data);
                    self::assertFalse(is_resource($child));
                    fwrite(STDOUT, json_encode(['fault' => 'oversized result payload', 'run_id' => $manifest['run_id'],
                        'bytes' => filesize($file), 'child_exit' => 0, 'refusal' => $e->getMessage(),
                        'reaped' => true, 'seconds' => round((hrtime(true) - $start) / 1e9, 3)], JSON_THROW_ON_ERROR).PHP_EOL);
                }
            }
        } finally {
            try {
                (new ReflectionMethod($harness, 'cleanup'))->invoke($harness);
                self::assertSame([], (new ReflectionProperty($harness, 'children'))->getValue($harness));
                self::assertFalse(is_resource($child), 'Cleanup must reap the child, not merely signal it.');
                self::assertLessThan(15.0, (hrtime(true) - $start) / 1e9, 'Fault and cleanup exceeded their bound.');
            } finally {
                // A failed cleanup assertion must not leak a child from the negative test itself.
                if (is_resource($child)) {
                    proc_terminate($child, 9);
                    proc_close($child);
                }
                foreach (glob($directory.'/*') as $file) {
                    unlink($file);
                }
                rmdir($directory);
            }
        }
    }

    public function test_missing_postgresql_blocker_cannot_pass_and_children_are_reaped(): void
    {
        $manifest = Ownership::manifest();
        $directory = $manifest['scratch'].'/missing-blocker-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $app = Ownership::boot();
        $harness = new ProcessHarness;
        $children = [];
        $start = hrtime(true);
        try {
            DB::statement('CREATE TABLE harness_blocker_fixture (id integer PRIMARY KEY)');
            DB::table('harness_blocker_fixture')->insert(['id' => 1]);
            self::assertSame(0, DB::connection()->transactionLevel());
            $coordinator = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
            $script = $directory.'/worker.php';
            file_put_contents($script, '<?php require '.var_export(__DIR__.'/Support/Ownership.php', true).'; '
                .'\\Tests\\PostgreSQL\\Support\\Ownership::boot(); '
                .'$db = \\Illuminate\\Support\\Facades\\DB::connection(); '
                .'if ($db->table("harness_blocker_fixture")->count() !== 1) exit(23); '
                .'$pid = (int) $db->selectOne("select pg_backend_pid() as pid")->pid; '
                .'$worker = $argv[1]; $phase = $worker === "A" ? "locked" : "attempt"; '
                .'file_put_contents(__DIR__."/$worker.$phase.json", json_encode(["backend_pid" => $pid])); '
                .'$deadline = hrtime(true) + 25000000000; '
                .'while (!is_file(__DIR__."/release")) { if (hrtime(true) > $deadline) exit(24); usleep(25000); }');
            $this->set($harness, 'directory', $directory);
            $this->set($harness, 'deadline', hrtime(true) + 40_000_000_000);
            $started = $pids = [];
            foreach (['A' => 'locked', 'B' => 'attempt'] as $worker => $phase) {
                $child = proc_open([PHP_BINARY, $script, $worker], [0 => ['pipe', 'r'],
                    1 => ['file', $directory.'/'.$worker.'.stdout', 'w'],
                    2 => ['file', $directory.'/'.$worker.'.stderr', 'w']], $pipes, $directory, Ownership::environment(), ['bypass_shell' => true]);
                self::assertIsResource($child);
                fclose($pipes[0]);
                $children[$worker] = $child;
                $started[$worker] = hrtime(true);
                $this->set($harness, 'children', $children);
                $this->set($harness, 'started', $started);
                $pids[$worker] = (new ReflectionMethod($harness, 'signal'))->invoke($harness, $worker, $phase);
            }
            self::assertCount(3, array_unique([$coordinator, ...array_values($pids)]));
            $metadata = static fn () => DB::select('select pid, pg_blocking_pids(pid)::text as blockers, wait_event_type, state from pg_stat_activity where pid in (?, ?) and datname = current_database() order by pid', array_values($pids));
            $before = $metadata();
            self::assertCount(2, $before);
            foreach ($before as $row) {
                self::assertSame('{}', $row->blockers);
                self::assertNotSame('Lock', $row->wait_event_type);
            }
            $observation = hrtime(true);
            try {
                (new ReflectionMethod($harness, 'observeBlocker'))->invoke($harness, $pids['A'], $pids['B']);
                self::fail('Missing PostgreSQL blocker was accepted.');
            } catch (RuntimeException $e) {
                self::assertSame('Actual PostgreSQL blocker relationship missing.', $e->getMessage());
            }
            $seconds = (hrtime(true) - $observation) / 1e9;
            self::assertGreaterThanOrEqual(5.0, $seconds);
            self::assertLessThan(8.0, $seconds);
            foreach ($children as $child) {
                self::assertTrue(proc_get_status($child)['running']);
            }
            self::assertEquals($before, $metadata());
            fwrite(STDOUT, json_encode(['fault' => 'missing PostgreSQL blocker relationship',
                'run_id' => $manifest['run_id'], 'container_id' => $manifest['container_id'],
                'coordinator_pid' => $coordinator, 'worker_pids' => $pids, 'both_signals' => true,
                'workers_alive' => true, 'metadata' => $before, 'observation_seconds' => round($seconds, 3),
                'refusal' => 'Actual PostgreSQL blocker relationship missing.'], JSON_THROW_ON_ERROR).PHP_EOL);
        } finally {
            try {
                (new ReflectionMethod($harness, 'cleanup'))->invoke($harness);
                self::assertSame([], (new ReflectionProperty($harness, 'children'))->getValue($harness));
                foreach ($children as $child) {
                    self::assertFalse(is_resource($child));
                }
                self::assertTrue(is_file($directory.'/release'));
                self::assertSame(0, DB::connection()->transactionLevel());
                self::assertSame(0, (int) DB::selectOne('select count(*) as n from pg_stat_activity where pid in (?, ?)', array_values($pids))->n);
                self::assertLessThan(20.0, (hrtime(true) - $start) / 1e9);
                fwrite(STDOUT, json_encode(['fault_cleanup' => 'missing blocker', 'reaped' => true,
                    'backend_sessions_remaining' => 0, 'release_attempted' => true], JSON_THROW_ON_ERROR).PHP_EOL);
            } finally {
                foreach ($children as $child) {
                    if (is_resource($child)) {
                        proc_terminate($child, 9);
                        proc_close($child);
                    }
                }
                DB::statement('DROP TABLE IF EXISTS harness_blocker_fixture');
                DB::purge();
                $app->flush();
                \Illuminate\Foundation\Bootstrap\HandleExceptions::flushState($this);
                \Illuminate\Support\Facades\Facade::clearResolvedInstances();
                \Mockery::close();
                foreach (glob($directory.'/*') as $file) {
                    unlink($file);
                }
                rmdir($directory);
            }
        }
    }

    private function set(ProcessHarness $harness, string $property, mixed $value): void
    {
        (new ReflectionProperty($harness, $property))->setValue($harness, $value);
    }
}
