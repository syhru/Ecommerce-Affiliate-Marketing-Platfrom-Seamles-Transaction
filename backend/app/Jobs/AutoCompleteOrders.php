<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\TrackingLog;
use App\Services\OrderService;
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
        // Completion goes through the canonical lifecycle path so the pending
        // commission is earned and the balance credited exactly once. Job
        // retries are safe: markCompleted is idempotent (WS-03 §5.2 / AC-06).
        $orderService = app(OrderService::class);

        Order::where('status', Order::STATUS_SHIPPED)
            ->where('shipped_at', '<=', Carbon::now()->subDays(7))
            ->each(function (Order $order) use ($orderService) {
                $orderService->markCompleted($order);

                TrackingLog::create([
                    'order_id'     => $order->id,
                    'status_title' => 'Pesanan Selesai (Otomatis)',
                    'description'  => 'Pesanan otomatis diselesaikan setelah 7 hari pengiriman.',
                ]);
            });
    }
}
