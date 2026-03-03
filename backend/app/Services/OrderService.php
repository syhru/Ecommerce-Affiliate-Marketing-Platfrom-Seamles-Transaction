<?php

namespace App\Services;

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
    ) {
    }

    /**
     * @param array $data   Validated payload
     * @param int   $customerId
     */
    public function createOrder(array $data, int $customerId): Order
    {
        return DB::transaction(function () use ($data, $customerId) {

            // DEBUG: log incoming affiliate data
            \Illuminate\Support\Facades\Log::info('OrderService::createOrder - incoming data', [
                'customer_id' => $customerId,
                'top_level_affiliate_code' => $data['affiliate_code'] ?? 'NOT SET',
                'items' => collect($data['items'] ?? [])->map(fn ($i) => [
                    'product_id' => $i['product_id'] ?? '?',
                    'affiliate_code' => $i['affiliate_code'] ?? 'NOT SET',
                ])->toArray(),
            ]);

            if (isset($data['product_id'])) {
                $rawItems = [[
                    'product_id'     => $data['product_id'],
                    'quantity'       => $data['quantity'] ?? 1,
                    'affiliate_code' => $data['affiliate_code'] ?? null,
                ]];
            } else {
                $rawItems = $data['items'] ?? [];
            }

            $resolvedItems = [];
            $subtotal      = 0;
            $commTotal     = 0;
            $primaryAffId  = null;
            $midtransItems = [];

            foreach ($rawItems as $raw) {
                $product   = Product::findOrFail($raw['product_id']);
                $qty       = max(1, (int) ($raw['quantity'] ?? 1));
                $itemTotal = round((float) $product->price * $qty, 2);
                $subtotal += $itemTotal;

                // Resolve per-item affiliate
                $affId       = null;
                $commRate    = 0;
                $commAmount  = 0;
                $affCode     = $raw['affiliate_code'] ?? null;

                if (! empty($affCode)) {
                    \Illuminate\Support\Facades\Log::info('OrderService - resolving affiliate', [
                        'affiliate_code_raw' => $affCode,
                        'affiliate_code_trimmed' => trim($affCode),
                    ]);

                    // Primary lookup: by referral_code in affiliate_profiles
                    $affProfile = AffiliateProfile::where('referral_code', trim($affCode))
                        ->where('status', 'active')
                        ->first();

                    // Fallback lookup: by user name -> then fetch their affiliate profile
                    if (! $affProfile) {
                        $affUser = \App\Models\User::where('name', trim($affCode))->first();
                        if ($affUser) {
                            $affProfile = AffiliateProfile::where('user_id', $affUser->id)
                                ->where('status', 'active')
                                ->first();
                        }
                    }

                    \Illuminate\Support\Facades\Log::info('OrderService - affiliate lookup result', [
                        'affiliate_code' => $affCode,
                        'found' => $affProfile ? true : false,
                        'profile_id' => $affProfile?->id,
                        'user_id' => $affProfile?->user_id,
                        'referral_code_in_db' => $affProfile?->referral_code,
                    ]);

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

            $shippingCost = (float) ($data['shipping_cost'] ?? 0);
            $totalAmount  = $subtotal + $shippingCost;
            $orderNumber  = 'TDR-' . strtoupper(Str::random(8));

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
                'shipping_courier' => $data['shipping_courier'],
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

                if ($ri['product']->stock !== null) {
                    $ri['product']->decrement('stock', $ri['qty']);
                }
            }

            if ($shippingCost > 0) {
                $midtransItems[] = [
                    'id'       => 'SHIPPING',
                    'price'    => (int) $shippingCost,
                    'quantity' => 1,
                    'name'     => 'Ongkos Kirim (' . $data['shipping_courier'] . ')',
                ];
            }

            //Build Midtrans Snap token
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
                    'finish'   => url('/checkout/success?order_number=' . $order->order_number),
                    'unfinish' => url('/checkout'),
                    'error'    => url('/checkout/failed'),
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
                'status'                  => 'verified',
                'midtrans_transaction_id' => $transactionId,
                'payment_verified_at'     => now(),
            ]);

            TrackingLog::create([
                'order_id'     => $locked->id,
                'status_title' => 'Pembayaran Dikonfirmasi',
                'description'  => 'Pembayaran telah diverifikasi via Midtrans.',
            ]);

            if ($locked->affiliate_id) {
                $commission = $this->affiliate->recordCommission($locked);
                if ($commission) {
                    $this->affiliate->earnCommission($commission);
                }
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
}
