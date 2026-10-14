<?php

use App\Models\AffiliateProfile;
use App\Models\AffiliateWithdrawal;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\AdminUserService;
use App\Services\AffiliateService;
use App\Services\MidtransService;
use App\Services\OrderService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\PostgreSQL\Support\Ownership;

require __DIR__.'/Ownership.php';

$result = ['scenario' => $argv[1] ?? '', 'worker' => $argv[2] ?? '', 'backend_pid' => null,
    'phase' => 'boot', 'outcome' => 'error', 'exception' => null, 'code' => null, 'sqlstate' => null,
    'provider_calls' => 0, 'http_status' => null, 'email_validation' => false, 'no_notification' => null,
    'terminal_at' => null, 'terminal_metadata' => null, 'exit' => 1];
$path = null;
try {
    $scenario = $result['scenario'];
    $worker = $result['worker'];
    if (! in_array($scenario, ['S1', 'S2a', 'S2b', 'S2c', 'S3', 'S4a', 'S4b', 'S4c', 'S5'], true)
        || ! in_array($worker, ['A', 'B'], true)) {
        throw new RuntimeException('Invalid worker schedule.');
    }
    $app = Ownership::boot();
    $manifest = Ownership::manifest();
    $directory = $manifest['scratch'].'/'.$scenario;
    $path = $directory.'/'.$worker.'.result.json';
    $fixture = json_decode(file_get_contents($directory.'/fixture.json'), true, flags: JSON_THROW_ON_ERROR);
    $connection = DB::connection();
    $result['backend_pid'] = (int) $connection->selectOne('select pg_backend_pid() as pid')->pid;
    $write = function (string $phase) use (&$result, $directory): void {
        $result['phase'] = $phase;
        $target = $directory.'/'.$result['worker'].'.'.$phase.'.json';
        file_put_contents($target.'.tmp', json_encode(['backend_pid' => $result['backend_pid']], JSON_THROW_ON_ERROR));
        rename($target.'.tmp', $target);
    };
    $pause = static function () use ($write, $directory): void {
        $write('locked');
        $deadline = hrtime(true) + 25_000_000_000;
        while (! is_file($directory.'/release')) {
            if (hrtime(true) > $deadline) {
                throw new RuntimeException('Release gate timeout.');
            }
            usleep(25000);
        }
    };

    // Load callers before installing the gate, so waiter services receive stale models.
    if ($scenario === 'S1') {
        $caller = Product::findOrFail($fixture['product']);
        User::findOrFail($fixture['buyer'.$worker]);
        $matches = static fn (string $sql, array $bindings): bool =>
            $sql === 'update "products" set "stock" = stock - 1, "updated_at" = ? where "id" = ? and "stock" >= ? and "products"."deleted_at" is null'
            && count($bindings) === 3 && is_string($bindings[0])
            && (int) $bindings[1] === (int) $fixture['product'] && (int) $bindings[2] === 1;
    } elseif (str_starts_with($scenario, 'S2')) {
        $caller = Order::findOrFail($fixture['order']);
        $matches = static fn (string $sql, array $bindings): bool =>
            $sql === 'select * from "orders" where "id" = ? limit 1 for update'
            && count($bindings) === 1 && (int) $bindings[0] === (int) $fixture['order'];
    } elseif ($scenario === 'S3') {
        $caller = AffiliateProfile::findOrFail($fixture['profile']);
        $matches = static fn (string $sql, array $bindings): bool =>
            $sql === 'select * from "affiliate_profiles" where "user_id" = ? limit 1 for update'
            && count($bindings) === 1 && (int) $bindings[0] === (int) $fixture['affiliate'];
    } elseif (str_starts_with($scenario, 'S4')) {
        $caller = AffiliateWithdrawal::findOrFail($fixture['withdrawal']);
        User::findOrFail($fixture['admin'.$worker]);
        $matches = static fn (string $sql, array $bindings): bool =>
            $sql === 'select * from "affiliate_withdrawals" where "id" = ? limit 1 for update'
            && count($bindings) === 1 && (int) $bindings[0] === (int) $fixture['withdrawal'];
    } else {
        $caller = User::findOrFail($fixture['user'.$worker]);
        $ids = User::where('role', 'superadmin')->orderBy('id')->pluck('id')->map(static fn ($id) => (int) $id)->all();
        $expected = [(int) $fixture['userA'], (int) $fixture['userB']];
        sort($expected);
        if ($ids !== $expected) {
            throw new RuntimeException('Shared admin lock fixture mismatch.');
        }
        // The real shared lock binds the role, not an ID; its entire row set is fixture-bound above.
        $matches = static fn (string $sql, array $bindings): bool =>
            $sql === 'select * from "users" where "role" = ? order by "id" asc for update'
            && $bindings === ['superadmin'];
        if ($worker === 'B') {
            $token = $caller->createToken('postgres-s5-worker')->plainTextToken;
        }
    }
    $write('ready');
    $fired = false;
    $stockUpdateSeen = false;
    if ($worker === 'A') {
        DB::listen(function ($query) use ($matches, $pause, $scenario, $caller, &$result, &$fired, &$stockUpdateSeen): void {
            if (str_starts_with($scenario, 'S2') && str_starts_with($query->sql, 'update "orders" set ')) {
                $fresh = $caller->fresh();
                $result['terminal_at'] = ($fresh->completed_at ?? $fresh->cancelled_at)?->toISOString();
            } elseif (str_starts_with($scenario, 'S4') && str_starts_with($query->sql, 'update "affiliate_withdrawals" set ')) {
                $result['terminal_at'] = $caller->fresh()->processed_at?->toISOString();
            }
            if (! $matches($query->sql, $query->bindings)) {
                return;
            }
            if ($scenario === 'S1') {
                $stockUpdateSeen = true;
                return;
            }
            if (! $fired) {
                $fired = true;
                $pause();
            }
        });
    } else {
        // Timestamps use second precision; separate the waiter's mutation time after the real lock resumes.
        DB::listen(static function ($query) use ($matches, $scenario): void {
            if ((str_starts_with($scenario, 'S2') || str_starts_with($scenario, 'S4'))
                && $matches($query->sql, $query->bindings)) {
                usleep(1100000);
            }
        });
        $connection->beforeExecuting(function ($sql, $bindings) use ($matches, $write, &$fired): void {
            if (! $fired && $matches($sql, $bindings)) {
                $fired = true;
                $write('attempt');
            }
        });
    }
    if ($scenario === 'S1') {
        app(MidtransService::class)->shouldReceive('createSnapToken')->andReturnUsing(
            function (array $params) use (&$result, &$fired, &$stockUpdateSeen, $worker, $caller, $pause): string {
                $result['provider_calls']++;
                if ($worker === 'A') {
                    if ($fired || ! $stockUpdateSeen || (int) $caller->fresh()->stock !== 0) {
                        throw new RuntimeException('Actual conditional reservation missing.');
                    }
                    $fired = true;
                    $pause();
                }
                return 'https://example.test/fake';
            }
        );
    }
    $result['phase'] = 'service';
    try {
        if ($scenario === 'S1') {
            $order = app(OrderService::class)->createOrder([
                'product_id' => $caller->id, 'quantity' => 1,
                'shipping_courier' => 'jne_reg', 'shipping_address' => 'Fixture address',
            ], (int) $fixture['buyer'.$worker]);
            $result['order_id'] = $order->id;
        } elseif (str_starts_with($scenario, 'S2')) {
            $complete = $scenario === 'S2c' || ($scenario === 'S2a' && $worker === 'A')
                || ($scenario === 'S2b' && $worker === 'B');
            if ($complete) {
                app(OrderService::class)->markCompleted($caller);
            } else {
                app(OrderService::class)->cancelOrder($caller, 'worker-'.$worker);
            }
        } elseif ($scenario === 'S3') {
            app(AffiliateService::class)->processWithdrawal($caller, 100000, [
                'bank_name' => 'TEST', 'bank_account_number' => '000000', 'bank_account_holder' => 'Fixture',
            ]);
        } elseif (str_starts_with($scenario, 'S4')) {
            $complete = ($scenario === 'S4b' && $worker === 'A') || ($scenario === 'S4c' && $worker === 'B');
            $processor = (int) $fixture['admin'.$worker];
            if ($complete) {
                app(AffiliateService::class)->completeWithdrawal($caller, $processor);
            } else {
                app(AffiliateService::class)->rejectWithdrawal($caller, 'worker-'.$worker, $processor);
            }
        } elseif ($worker === 'A') {
            app(AdminUserService::class)->update($caller, ['is_active' => false]);
        } else {
            $kernel = app(Kernel::class);
            $request = Request::create('/api/user/profile', 'PUT', [], [], [], [
                'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ], json_encode(['email' => 'postgres-s5-changed@example.test', 'current_password' => 'password'], JSON_THROW_ON_ERROR));
            $response = $kernel->handle($request);
            $result['http_status'] = $response->getStatusCode();
            $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
            $result['email_validation'] = ($body['errors']['email'] ?? null) === [
                'Operasi ini akan menghilangkan superadmin aktif terverifikasi terakhir.',
            ];
            $kernel->terminate($request, $response);
            if ($result['http_status'] !== 422 || ! $result['email_validation']
                || array_keys($body['errors'] ?? []) !== ['email']) {
                throw new RuntimeException('Unexpected profile response.');
            }
            $result['outcome'] = 'profile-rejected';
        }
        if ($result['outcome'] !== 'profile-rejected') {
            $result['outcome'] = 'success';
        }
    } catch (InvalidArgumentException $e) {
        if ($scenario === 'S3' && $e->getMessage() === 'Anda masih memiliki permintaan penarikan yang sedang diproses.') {
            $result['outcome'] = 'pending-rejected';
        } elseif ($scenario === 'S1' && $worker === 'B'
            && $e->getMessage() === sprintf('Stok produk "%s" tidak mencukupi. Tersisa 0, diminta 1.', $caller->name)) {
            $result['outcome'] = 'stock-rejected';
        } else {
            throw $e;
        }
        $result['exception'] = $e::class;
        $result['code'] = $e->getCode();
    } catch (DomainException $e) {
        $verb = $scenario === 'S2a' ? 'dibatalkan' : 'diselesaikan';
        if ($worker !== 'B' || ! in_array($scenario, ['S2a', 'S2b'], true)
            || $e->getMessage() !== "Pesanan #{$caller->order_number} tidak dapat {$verb} (status: {$caller->status}).") {
            throw $e;
        }
        $result['outcome'] = 'terminal-rejected';
        $result['exception'] = $e::class;
        $result['code'] = $e->getCode();
    }
    if (! $fired || $connection->transactionLevel() !== 0) {
        throw new RuntimeException('Actual service gate/commit missing.');
    }
    if (str_starts_with($scenario, 'S2')) {
        $fresh = $caller->fresh();
        if ($worker === 'B') {
            $result['terminal_at'] = ($fresh->completed_at ?? $fresh->cancelled_at)?->toISOString();
        }
        $result['terminal_metadata'] = ['status' => $fresh->status, 'cancellation_reason' => $fresh->cancellation_reason];
    } elseif (str_starts_with($scenario, 'S4')) {
        $fresh = $caller->fresh();
        if ($worker === 'B') {
            $result['terminal_at'] = $fresh->processed_at?->toISOString();
        }
        $result['terminal_metadata'] = ['status' => $fresh->status, 'processed_by' => $fresh->processed_by,
            'rejection_reason' => $fresh->rejection_reason];
    } elseif ($scenario === 'S5') {
        $result['no_notification'] = Notification::sentNotifications() === [];
        if (! $result['no_notification']) {
            throw new RuntimeException('Unexpected profile notification.');
        }
    }
    $result['phase'] = 'finished';
    $result['exit'] = 0;
} catch (Throwable $e) {
    $result['outcome'] = 'error';
    $result['exception'] = $e::class;
    $result['code'] = $e->getCode();
    if ($e instanceof PDOException || $e instanceof Illuminate\Database\QueryException) {
        $result['sqlstate'] = $e->errorInfo[0] ?? null;
    }
} finally {
    if (isset($app)) {
        DB::purge();
    }
    $encoded = json_encode($result, JSON_THROW_ON_ERROR);
    if ($path !== null) {
        file_put_contents($path.'.tmp', $encoded);
        rename($path.'.tmp', $path);
    }
    fwrite(STDOUT, $encoded.PHP_EOL);
}
exit($result['exit']);
