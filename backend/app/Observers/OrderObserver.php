<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\NotificationService;

class OrderObserver
{
    public function __construct(protected NotificationService $notif) {}

    public function updated(Order $order): void
    {
        if ($order->wasChanged('status')) {
            $event = match ($order->status) {
                'processing' => 'order.processing',
                'shipped'    => 'order.shipped',
                'completed'  => 'order.delivered',
                'cancelled'  => 'order.cancelled',
                default      => null,
            };

            if ($event) {
                $this->notif->notifyOrderStatus($order, $event);
            }

            if ($order->status === 'completed') {
                $this->notif->notifyAffiliateBalanceCredited($order);
            }
        }
    }
}
