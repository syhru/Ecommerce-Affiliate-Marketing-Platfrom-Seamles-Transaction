<?php

namespace App\Services;

use App\Models\AffiliateCommission;
use App\Models\AffiliateProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\TrackingLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderService
{
    public function __construct(
        protected MidtransService    $midtrans,
        protected AffiliateService   $affiliate,
        protected NotificationService $notification,
        protected ShippingRateService $shippingRates,
        protected ProductInventoryService $inventory,
    ) {
    }

    /**
     * Normalize the incoming order payload into one quantity per product.
     *
     * Duplicate product entries are summed so they can never be used to slip
     * past per-line stock validation (WS-02 §5.1 / AC-04).
     *
     * @param  array  $data  Validated payload
     * @return array<int, array{product_id: int, quantity: int, affiliate_code: string|null}>
     */
    protected function normalizeItems(array $data): array
    {
        // Legacy single-product payload (product_id at top level).
        if (isset($data['product_id'])) {
            return [[
                'product_id'     => (int) $data['product_id'],
                'quantity'       => max(1, (int) ($data['quantity'] ?? 1)),
                'affiliate_code' => $data['affiliate_code'] ?? null,
            ]];
        }

        $merged = [];

        foreach ($data['items'] ?? [] as $raw) {
            $productId = (int) $raw['product_id'];
            $quantity  = max(1, (int) ($raw['quantity'] ?? 1));
            $affCode   = $raw['affiliate_code'] ?? null;

            if (isset($merged[$productId])) {
                $merged[$productId]['quantity'] += $quantity;
            } else {
                $merged[$productId] = [
                    'product_id'     => $productId,
                    'quantity'       => $quantity,
                    'affiliate_code' => $affCode,
                ];
            }
        }

        return array_values($merged);
    }

    /**
     * @param  array  $data   Validated payload
     * @param  int    $customerId
     *
     * @throws \InvalidArgumentException When stock is insufficient or the
     *                                   courier/service is not supported.
     */
    public function createOrder(array $data, int $customerId): Order
    {
        // Server-side shipping rate is authoritative (PA-F-03 / AC-07, AC-09).
        // Resolved up-front so an unsupported courier fails before any stock is
        // touched or any order row is written.
        $courier      = $data['shipping_courier'];
        $shippingCost = $this->shippingRates->cost($courier);

        // Normalize duplicates first: validation and reservation always see the
        // effective total quantity per product.
        $items = $this->normalizeItems($data);

        return DB::transaction(function () use ($data, $customerId, $items, $courier, $shippingCost) {
            $subtotal      = 0;
            $commTotal     = 0;
            $primaryAffId  = null;
            $resolvedItems = [];
            $midtransItems = [];

            foreach ($items as $item) {
                $product = Product::findOrFail($item['product_id']);
                $qty     = $item['quantity'];

                // Reserve stock atomically *before* creating any order row.
                // This throws when stock is insufficient, which aborts the whole
                // transaction — order rows, items and stock all roll back
                // together, and Midtrans is never called.
                $this->inventory->reserve($product, $qty);

                $itemTotal = round((float) $product->price * $qty, 2);
                $subtotal += $itemTotal;

                // Resolve affiliate by canonical referral_code only (PA-F-07).
                $affId      = null;
                $commRate   = 0;
                $commAmount = 0;
                $affCode    = $item['affiliate_code'];

                if (! empty($affCode)) {
                    $affProfile = AffiliateProfile::where('referral_code', trim($affCode))
                        ->where('status', 'active')
                        ->first();

                    if ($affProfile) {
                        $affId      = $affProfile->user_id;
                        $commRate   = $affProfile->commission_rate;
                        $commAmount = round($itemTotal * ($commRate / 100), 2);
                        $commTotal += $commAmount;

                        if (! $primaryAffId) {
                            $primaryAffId = $affId;
                        }
                    }
                }

                $resolvedItems[] = compact('product', 'qty', 'itemTotal', 'affId', 'affCode', 'commAmount');

                $midtransItems[] = [
                    'id'       => $product->id,
                    'price'    => (int) $product->price,
                    'quantity' => $qty,
                    'name'     => mb_substr($product->name, 0, 50),
                ];
            }

            // All money the client can be charged is computed here, server-side.
            $totalAmount = round($subtotal + $shippingCost, 2);
            $orderNumber = 'TDR-' . strtoupper(Str::random(8));

            /** @var Order $order */
            $order = Order::create([
                'order_number'     => $orderNumber,
                'customer_id'      => $customerId,
                'affiliate_id'     => $primaryAffId,
                'subtotal'         => $subtotal,
                'commission_amount' => $commTotal,
                'shipping_cost'    => $shippingCost,
                'total_amount'     => $totalAmount,
                'status'           => 'pending',
                'payment_method'   => $data['payment_method'] ?? null,
                'shipping_address' => $data['shipping_address'],
                'shipping_courier' => $courier,
                'notes'            => $data['notes'] ?? null,
            ]);

            foreach ($resolvedItems as $ri) {
                OrderItem::create([
                    'order_id'          => $order->id,
                    'product_id'        => $ri['product']->id,
                    'product_name'      => $ri['product']->name,
                    'product_price'     => $ri['product']->price,
                    'quantity'          => $ri['qty'],
                    'subtotal'          => $ri['itemTotal'],
                    'affiliate_code'    => $ri['affCode'],
                    'commission_amount' => $ri['commAmount'],
                ]);
            }

            if ($shippingCost > 0) {
                $midtransItems[] = [
                    'id'       => 'SHIPPING',
                    'price'    => $shippingCost,
                    'quantity' => 1,
                    'name'     => 'Ongkos Kirim (' . $courier . ')',
                ];
            }

            // External boundary: the Midtrans call is made only after the order
            // and its items are fully written and the stock is reserved, and it
            // is the LAST step inside the transaction. If it throws, the whole
            // transaction — order, items and stock — rolls back together.
            $snapUrl = $this->midtrans->createSnapToken([
                'transaction_details' => [
                    'order_id'     => $order->order_number,
                    'gross_amount' => (int) $totalAmount,
                ],
                'customer_details' => [
                    'first_name' => $order->customer?->name ?? 'Customer',
                    'email'      => $order->customer?->email ?? '',
                ],
                'item_details' => $midtransItems,
                'callbacks' => [
                    'finish'   => env('FRONTEND_URL', 'http://localhost:3000') . '/orders/' . $order->order_number,
                    'unfinish' => env('FRONTEND_URL', 'http://localhost:3000') . '/checkout',
                    'error'    => env('FRONTEND_URL', 'http://localhost:3000') . '/checkout/failed',
                ],
            ]);

            $order->update(['midtrans_snap_token' => $snapUrl]);

            TrackingLog::create([
                'order_id'     => $order->id,
                'status_title' => 'Pesanan Dibuat',
                'description'  => 'Pesanan berhasil dibuat, menunggu pembayaran.',
            ]);

            return $order->fresh();
        });
    }

    public function checkAndVerifyPayment(Order $order): bool
    {
        if ($order->payment_verified_at) {
            return true;
        }

        $result = $this->midtrans->getTransactionStatus($order->order_number);

        if (! $result) {
            return false;
        }

        $status      = $result['transaction_status'] ?? '';
        $fraudStatus = $result['fraud_status'] ?? '';
        $txId        = $result['transaction_id'] ?? ('MIDTRANS-' . now()->timestamp);

        $isSettled = in_array($status, ['settlement', 'capture'])
            && ($fraudStatus === 'accept' || $fraudStatus === '');

        if ($isSettled) {
            $this->verifyPayment($order, $txId);
            return true;
        }

        return false;
    }


    public function verifyPayment(Order $order, string $transactionId): void
    {
        $commission = null;
        $processed  = false;

        DB::transaction(function () use ($order, $transactionId, &$commission, &$processed) {
            $locked = Order::where('id', $order->id)
                ->whereNull('payment_verified_at')
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            $locked->update([
                'status'                  => Order::STATUS_VERIFIED,
                'midtrans_transaction_id' => $transactionId,
                'payment_verified_at'     => now(),
            ]);

            TrackingLog::create([
                'order_id'     => $locked->id,
                'status_title' => 'Pembayaran Dikonfirmasi',
                'description'  => 'Pembayaran telah diverifikasi via Midtrans.',
            ]);

            // Commission is created as pending only. It must NOT be earned yet
            // and the balance must NOT move: `pending → earned` happens only
            // when the order completes (WS-03 §3.2 / PA-F-08 / AC-02, AC-03).
            if ($locked->affiliate_id) {
                $commission = $this->affiliate->recordCommission($locked);
            }

            $processed = true;
        });
        if (! $processed) {
            return;
        }

        $freshOrder = $order->fresh();

        $this->notification->notifyOrderStatus($freshOrder, 'payment.confirmed');

        if ($commission) {
            $this->notification->notifyAffiliateCommission($commission);
        }
    }

    /**
     * Complete an order: `shipped → completed`, earning the pending commission
     * and crediting the affiliate balance exactly once (WS-03 §3.2, §5.2 /
     * PA-F-08 / AC-04, AC-05, AC-06).
     *
     * This is the single canonical completion path. The scheduler command, the
     * auto-complete job and the Filament admin action all funnel through here,
     * so no path can mark an order completed without earning its commission.
     *
     * Idempotent: a lockForUpdate on the order row plus the commission's own
     * pending→earned guard make repeated completion or a retried job a no-op.
     *
     * @throws \DomainException When the order is not in a completable state.
     */
    public function markCompleted(Order $order): void
    {
        $commission = null;
        $processed  = false;

        DB::transaction(function () use ($order, &$commission, &$processed) {
            $locked = Order::where('id', $order->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            // A completed order stays completed; a cancelled order is terminal
            // and must never be completed (WS-03 §6).
            if (! in_array($locked->status, Order::COMPLETABLE_STATUSES, true)) {
                if ($locked->status === Order::STATUS_COMPLETED) {
                    $processed = true; // already completed — no-op
                }
                return;
            }

            $locked->update([
                'status'       => Order::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            // pending commission → earned, balance += amount, total_earned
            // += amount, all inside the same transaction as the status change.
            if ($locked->affiliate_id) {
                $commission = AffiliateCommission::where('order_id', $locked->id)->first();

                if ($commission) {
                    $this->affiliate->earnCommission($commission);
                }
            }

            $processed = true;
        });

        if (! $processed) {
            throw new \DomainException(
                "Pesanan #{$order->order_number} tidak dapat diselesaikan (status: {$order->status})."
            );
        }

        // The `order.delivered` status notification is sent by the OrderObserver
        // (the row it is written with is part of this transaction, so a rollback
        // silently undoes it). This service only owns the *financial*
        // notification, and only after the credit has actually committed
        // (WS-03 §5.6 / AC-19).
        if ($commission) {
            $this->notification->notifyAffiliateBalanceCredited($order->fresh());
        }
    }

    /**
     * Cancel an order that has not been completed: restore its stock once,
     * void its pending commission, record tracking history (WS-03 §3.3, §5.3 /
     * PA-F-09 / AC-07..AC-12).
     *
     * This is the single canonical cancellation path. Cancellation never
     * claims a payment refund was issued — automatic Midtrans refunds are
     * explicitly out of scope for WS-03.
     *
     * Idempotent: an already-cancelled order is re-locked and left untouched,
     * so stock is restored and a commission voided exactly once.
     *
     * @throws \DomainException When the order cannot be cancelled.
     */
    public function cancelOrder(Order $order, ?string $reason = null): void
    {
        $processed = false;

        DB::transaction(function () use ($order, $reason, &$processed) {
            $locked = Order::where('id', $order->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            // Idempotent: an already-cancelled order is a no-op.
            if ($locked->status === Order::STATUS_CANCELLED) {
                $processed = true;
                return;
            }

            // A completed order is terminal for normal cancellation; refunds
            // and returns after completion are a separate flow (WS-03 §3.3).
            if (! in_array($locked->status, Order::CANCELLABLE_STATUSES, true)) {
                return;
            }

            $locked->update([
                'status'              => Order::STATUS_CANCELLED,
                'cancelled_at'        => now(),
                'cancellation_reason' => $reason,
            ]);

            // Restore stock for every item, exactly once (mirrors the
            // reservation done at creation).
            foreach ($locked->items as $item) {
                $product = Product::find($item->product_id);

                if ($product) {
                    $this->inventory->release($product, $item->quantity);
                }
            }

            // Void the pending commission. It was never credited, so the
            // balance and total_earned must not change (AC-08).
            if ($locked->affiliate_id) {
                $commission = AffiliateCommission::where('order_id', $locked->id)->first();

                if ($commission) {
                    $this->affiliate->cancelCommission($commission);
                }
            }

            TrackingLog::create([
                'order_id'     => $locked->id,
                'status_title' => 'Pesanan Dibatalkan',
                'description'  => $reason ?: 'Pesanan dibatalkan. Stok telah dikembalikan.',
            ]);

            $processed = true;
        });

        if (! $processed) {
            throw new \DomainException(
                "Pesanan #{$order->order_number} tidak dapat dibatalkan (status: {$order->status})."
            );
        }

        // The `order.cancelled` status notification is owned by the OrderObserver
        // and its copy is deliberately free of any refund claim (AC-12, AC-19).
        // Cancellation has no separate financial notification to send here.
    }

    /**
     * Handle a final-failed Midtrans payment (deny, cancel, expire, failure).
     * Cancels the order and restores stock. Idempotent & safe against double webhooks.
     *
     * @param  string  $midtransStatus  Raw transaction_status from Midtrans
     */
    public function cancelFailedPayment(Order $order, string $midtransStatus): void
    {
        DB::transaction(function () use ($order, $midtransStatus) {
            /** @var Order|null $locked */
            $locked = Order::where('id', $order->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            // Idempotent & safe: only a still-pending order may be cancelled here.
            // A verified payment or an order that already progressed (paid, processing,
            // shipped, completed) or was already cancelled must never be touched —
            // this also prevents double-restoring stock on duplicate webhooks.
            if ($locked->payment_verified_at || $locked->status !== 'pending') {
                return;
            }

            $locked->update([
                'status'              => Order::STATUS_CANCELLED,
                'cancelled_at'        => now(),
                'cancellation_reason' => "Pembayaran gagal/batal/expired via Midtrans: {$midtransStatus}",
            ]);

            // Restore stock for every item (mirrors the reservation done at creation).
            foreach ($locked->items as $item) {
                $product = Product::find($item->product_id);
                if ($product) {
                    $this->inventory->release($product, $item->quantity);
                }
            }

            TrackingLog::create([
                'order_id'     => $locked->id,
                'status_title' => 'Pembayaran Gagal/Batal',
                'description'  => "Pembayaran dibatalkan oleh Midtrans (status: {$midtransStatus}). Pesanan dibatalkan dan stok dikembalikan.",
            ]);
        });
    }
}
