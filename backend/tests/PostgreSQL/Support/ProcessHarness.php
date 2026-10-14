<?php

namespace Tests\PostgreSQL\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ProcessHarness
{
    private array $children = [];
    private string $directory;
    private int $deadline;
    private array $started = [];

    public function run(string $scenario, array $fixture): array
    {
        $manifest = Ownership::manifest();
        $this->directory = $manifest['scratch'].'/'.$scenario;
        if (! mkdir($this->directory, 0700)) {
            throw new RuntimeException('Scenario directory already exists.');
        }
        file_put_contents($this->directory.'/fixture.json', json_encode($fixture, JSON_THROW_ON_ERROR));
        $start = hrtime(true);
        $this->deadline = $start + 40_000_000_000;
        $coordinator = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
        try {
            if (DB::connection()->transactionLevel() !== 0) {
                throw new RuntimeException('Fixture must be committed.');
            }
            $this->start($scenario, 'A');
            $a = $this->signal('A', 'locked');
            $this->start($scenario, 'B');
            $b = $this->signal('B', 'attempt');
            if (count(array_unique([$coordinator, $a, $b])) !== 3) {
                throw new RuntimeException('Backend PIDs are not independent.');
            }
            $this->observeBlocker($a, $b);
            touch($this->directory.'/release');
            $results = [];
            foreach (['A', 'B'] as $worker) {
                $results[$worker] = $this->finish($worker, $scenario);
                if ($results[$worker]['backend_pid'] !== ($worker === 'A' ? $a : $b)) {
                    throw new RuntimeException('Backend PID changed.');
                }
            }
            return ['scenario' => $scenario, 'coordinator_pid' => $coordinator,
                'A' => $results['A'], 'B' => $results['B'], 'blocker_observed' => true,
                'seconds' => round((hrtime(true) - $start) / 1e9, 3)];
        } finally {
            $this->cleanup();
        }
    }

    private function observeBlocker(int $a, int $b): void
    {
        $deadline = hrtime(true) + 5_000_000_000;
        $blocked = false;
        do {
            $row = DB::selectOne("select ?::integer = any(pg_blocking_pids(pid)) as blocked, wait_event_type from pg_stat_activity where pid = ? and datname = current_database()", [$a, $b]);
            $blocked = $row && $row->blocked && $row->wait_event_type === 'Lock';
            if ($blocked) {
                break;
            }
            $this->assertAlive('A');
            $this->assertAlive('B');
            usleep(25000);
        } while (hrtime(true) < $deadline);
        if (! $blocked) {
            throw new RuntimeException('Actual PostgreSQL blocker relationship missing.');
        }
    }

    private function cleanup(): void
    {
        touch($this->directory.'/release');
        $failure = false;
        foreach ($this->children as $worker => $child) {
            $status = proc_get_status($child);
            if ($status['running']) {
                proc_terminate($child);
                $deadline = hrtime(true) + 2_000_000_000;
                do {
                    usleep(25000);
                    $status = proc_get_status($child);
                } while ($status['running'] && hrtime(true) < $deadline);
                if ($status['running']) {
                    proc_terminate($child, 9);
                    $deadline = hrtime(true) + 2_000_000_000;
                    do {
                        usleep(25000);
                        $status = proc_get_status($child);
                    } while ($status['running'] && hrtime(true) < $deadline);
                }
            }
            if (proc_get_status($child)['running']) {
                $failure = true;
            } else {
                proc_close($child);
            }
        }
        $this->children = [];
        if ($failure) {
            throw new RuntimeException('Worker cleanup did not complete within its bound.');
        }
    }

    private function start(string $scenario, string $worker): void
    {
        $pipes = [];
        $child = proc_open([PHP_BINARY, __DIR__.'/worker.php', $scenario, $worker], [
            0 => ['pipe', 'r'], 1 => ['file', $this->directory.'/'.$worker.'.stdout', 'w'],
            2 => ['file', $this->directory.'/'.$worker.'.stderr', 'w'],
        ], $pipes, dirname(__DIR__, 3), Ownership::environment(), ['bypass_shell' => true]);
        if (! is_resource($child)) {
            throw new RuntimeException('Worker creation failed.');
        }
        fclose($pipes[0]);
        $this->children[$worker] = $child;
        $this->started[$worker] = hrtime(true);
    }

    private function signal(string $worker, string $phase): int
    {
        $file = $this->directory.'/'.$worker.'.'.$phase.'.json';
        $deadline = hrtime(true) + 10_000_000_000;
        while (! is_file($file)) {
            $this->assertAlive($worker);
            if (hrtime(true) >= $deadline) {
                throw new RuntimeException('Missing worker '.$worker.' '.$phase.' gate.');
            }
            usleep(25000);
        }
        $data = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        if (! is_int($data['backend_pid'] ?? null) || $data['backend_pid'] < 1) {
            throw new RuntimeException('Invalid backend PID signal.');
        }
        return $data['backend_pid'];
    }

    private function assertAlive(string $worker): void
    {
        $this->assertBound($worker);
        if (! proc_get_status($this->children[$worker])['running']) {
            $file = $this->directory.'/'.$worker.'.result.json';
            $detail = '';
            if (is_file($file) && filesize($file) <= 4096) {
                $data = json_decode(file_get_contents($file), true);
                $detail = ' ('.($data['phase'] ?? 'unknown').', '.($data['exception'] ?? 'unknown').')';
            }
            throw new RuntimeException('Worker '.$worker.' exited before gate/blocker observation.'.$detail);
        }
    }

    private function assertBound(string $worker): void
    {
        if (hrtime(true) > $this->deadline || hrtime(true) - $this->started[$worker] > 30_000_000_000) {
            throw new RuntimeException('Scenario/worker hard wall clock exceeded.');
        }
        foreach (['stdout', 'stderr'] as $stream) {
            $path = $this->directory.'/'.$worker.'.'.$stream;
            clearstatcache(true, $path);
            if (is_file($path) && filesize($path) > 16384) {
                throw new RuntimeException('Worker output exceeded bound.');
            }
        }
    }

    private function finish(string $worker, string $scenario): array
    {
        $deadline = hrtime(true) + 10_000_000_000;
        do {
            $this->assertBound($worker);
            $status = proc_get_status($this->children[$worker]);
            if (! $status['running']) {
                break;
            }
            usleep(25000);
        } while (hrtime(true) < $deadline);
        if ($status['running']) {
            throw new RuntimeException('Worker result timeout.');
        }
        $exit = $status['exitcode'];
        proc_close($this->children[$worker]);
        unset($this->children[$worker]);
        $file = $this->directory.'/'.$worker.'.result.json';
        if ($exit !== 0 || ! is_file($file) || filesize($file) > 4096) {
            throw new RuntimeException('Worker failed or result absent/oversized (exit '.$exit.').');
        }
        $result = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        if (($result['scenario'] ?? null) !== $scenario || ($result['worker'] ?? null) !== $worker
            || ($result['phase'] ?? null) !== 'finished' || ($result['exit'] ?? null) !== 0
            || ($result['outcome'] ?? 'error') === 'error') {
            throw new RuntimeException('Invalid worker result.');
        }
        return $result;
    }
}
