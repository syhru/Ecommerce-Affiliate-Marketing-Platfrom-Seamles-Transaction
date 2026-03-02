<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\TrackingLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

class AutoCompleteOrders implements ShouldQueue
{
    use Queueable;

    public function __construct() {}

    //Auto-complete orders that have been shipped for more than 7 days.
    public function handle(): void
    {
        Order::where('status', 'shipped')
            ->where('shipped_at', '<=', Carbon::now()->subDays(7))
            ->each(function (Order $order) {
                $order->update([
                    'status'       => 'completed',
                    'completed_at' => now(),
                ]);

                TrackingLog::create([
                    'order_id'     => $order->id,
                    'status_title' => 'Pesanan Selesai (Otomatis)',
                    'description'  => 'Pesanan otomatis diselesaikan setelah 7 hari pengiriman.',
                ]);
            });
    }
}
