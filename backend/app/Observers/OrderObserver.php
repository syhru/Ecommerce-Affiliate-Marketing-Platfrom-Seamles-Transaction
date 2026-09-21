<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\NotificationService;

/**
 * Order status notifications (WS-03).
 *
 * Financial notifications are NOT fired here. `affiliate.balance_credited` is
 * sent by OrderService::markCompleted() after the commission has actually been
 * earned and the balance actually credited — firing it from the observer
 * would announce a credit that a bare `status` update never performed
 * (PA-F-08 / AC-05, AC-19).
 *
 * This observer only handles the pure status notification, and it maps the
 * canonical `verified` status (never `paid`) to the payment.confirmed event.
 */
class OrderObserver
{
    public function __construct(protected NotificationService $notif) {}

    public function updated(Order $order): void
    {
        if (! $order->wasChanged('status')) {
            return;
        }

        // A status transition out of sequence (e.g. an already-cancelled order
        // being re-completed) must not produce a notification that describes
        // a transition that never happened.
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
    }
}
