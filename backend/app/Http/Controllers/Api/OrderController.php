<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CreateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\TrackingLogResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function __construct(protected OrderService $orderService)
    {
    }

    /** POST /api/orders */
    public function store(CreateOrderRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Resolve affiliate code from multiple sources (fallback chain)
        $affiliateCode = $request->input('affiliate_code')
            ?? $request->cookie('tdr_affiliate_ref')
            ?? $request->header('X-Affiliate-Code');

        // Inject affiliate_code into every item that doesn't already have one
        if (! empty($affiliateCode) && isset($data['items'])) {
            foreach ($data['items'] as &$item) {
                if (empty($item['affiliate_code'])) {
                    $item['affiliate_code'] = $affiliateCode;
                }
            }
            unset($item);
        }

        // Also keep it at top level for single-product format compatibility
        $data['affiliate_code'] = $affiliateCode;

        $order = $this->orderService->createOrder($data, $request->user()->id);

        return response()->json([
            'message'        => 'Pesanan berhasil dibuat.',
            'order'          => new OrderResource($order->load(['items', 'trackingLogs'])),
            'payment_url'    => $order->midtrans_snap_token,
        ], 201);
    }

    /** GET /api/user/orders */
    public function userOrders(Request $request): AnonymousResourceCollection
    {
        $orders = Order::where('customer_id', $request->user()->id)
            ->with('items')
            ->latest()
            ->paginate($request->integer('per_page', 10));

        return OrderResource::collection($orders);
    }

    /** GET /api/orders/{idOrOrderNumber} */
    public function show(Request $request, $idOrOrderNumber): OrderResource
    {
        $order = Order::with(['items', 'trackingLogs', 'customer'])
            ->where(function ($query) use ($idOrOrderNumber) {
                if (is_numeric($idOrOrderNumber)) {
                    $query->where('id', $idOrOrderNumber);
                } else {
                    $query->where('order_number', $idOrOrderNumber);
                }
            })
            ->where('customer_id', $request->user()->id)
            ->firstOrFail();

        return new OrderResource($order);
    }

    /** GET /api/orders/{idOrOrderNumber}/tracking */
    public function tracking(Request $request, $idOrOrderNumber): AnonymousResourceCollection
    {
        $order = Order::where(function ($query) use ($idOrOrderNumber) {
            if (is_numeric($idOrOrderNumber)) {
                $query->where('id', $idOrOrderNumber);
            } else {
                $query->where('order_number', $idOrOrderNumber);
            }
        })
            ->where('customer_id', $request->user()->id)
            ->firstOrFail();

        return TrackingLogResource::collection($order->trackingLogs()->orderBy('created_at')->get());
    }
}
