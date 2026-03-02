<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\MidtransService;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MidtransWebhookController extends Controller
{
    public function __construct(
        protected MidtransService $midtrans,
        protected OrderService    $orderService,
    ) {}

    /** POST /api/webhooks/midtrans */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();

        // Validate Midtrans signature
        if (! $this->midtrans->isValidSignature($payload)) {
            Log::warning('Midtrans webhook: invalid signature', ['payload' => $payload]);
            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        $status        = $payload['transaction_status'] ?? '';
        $fraudStatus   = $payload['fraud_status'] ?? '';
        $orderNumber   = $payload['order_id'] ?? '';
        $transactionId = $payload['transaction_id'] ?? '';

        // Only process confirmed / settled payments
        $isSettled = in_array($status, ['settlement', 'capture'])
            && ($fraudStatus === 'accept' || $fraudStatus === '');

        if (! $isSettled) {
            Log::info('Midtrans webhook: skipped status', compact('status', 'orderNumber'));
            return response()->json(['message' => 'Ignored.']);
        }

        $order = Order::where('order_number', $orderNumber)->first();

        if (! $order) {
            Log::error('Midtrans webhook: order not found', ['order_number' => $orderNumber]);
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if ($order->payment_verified_at) {
            // Already processed — idempotent response
            return response()->json(['message' => 'Already processed.']);
        }

        try {
            $this->orderService->verifyPayment($order, $transactionId);
        } catch (\Throwable $e) {
            Log::error('Midtrans webhook: verifyPayment failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Internal error.'], 500);
        }

        return response()->json(['message' => 'OK']);
    }
}
