<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\TrackingLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;


class AdvanceOrderStatus extends Command
{
    protected $signature   = 'orders:advance-status';
    protected $description = 'Auto-advance order statuses based on elapsed time after payment';

    public function handle(): int
    {
        $now     = Carbon::now();
        $advanced = 0;

        // ── 1. verified → processing (1 menit setelah payment_verified_at)
        Order::whereNotNull('payment_verified_at')
            ->where('status', 'verified')
            ->where('payment_verified_at', '<=', $now->copy()->subMinute())
            ->each(function (Order $order) use (&$advanced) {
                $order->update(['status' => 'processing']);
                TrackingLog::create([
                    'order_id'     => $order->id,
                    'status_title' => 'Sedang Diproses',
                    'description'  => 'Pesanan sedang dalam proses pengemasan.',
                ]);
                $advanced++;
                $this->line("  ✅ #{$order->order_number}: verified → processing");
            });

        // ── 2. processing → shipped (5 menit setelah updated_at)
        Order::where('status', 'processing')
            ->where('updated_at', '<=', $now->copy()->subMinutes(5))
            ->each(function (Order $order) use (&$advanced) {
                $order->update(['status' => 'shipped', 'shipped_at' => now()]);
                TrackingLog::create([
                    'order_id'     => $order->id,
                    'status_title' => 'Pesanan Dikirim',
                    'description'  => 'Pesanan telah dikirim dan sedang dalam perjalanan.',
                ]);
                $advanced++;
                $this->line("  🚚 #{$order->order_number}: processing → shipped");
            });

        // ── 3. shipped → completed (15 menit setelah shipped_at)
        Order::where('status', 'shipped')
            ->where(function ($q) use ($now) {
                $q->whereNotNull('shipped_at')
                  ->where('shipped_at', '<=', $now->copy()->subMinutes(15));
            })
            ->each(function (Order $order) use (&$advanced) {
                $order->update(['status' => 'completed', 'completed_at' => now()]);
                TrackingLog::create([
                    'order_id'     => $order->id,
                    'status_title' => 'Pesanan Diterima',
                    'description'  => 'Pesanan telah selesai. Terima kasih telah berbelanja!',
                ]);
                $advanced++;
                $this->line("  🎉 #{$order->order_number}: shipped → completed");
            });

        if ($advanced === 0) {
            $this->line('  No orders to advance.');
        } else {
            $this->info("  Advanced {$advanced} order(s).");
        }

        return self::SUCCESS;
    }
}
