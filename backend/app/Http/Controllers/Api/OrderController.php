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
        if (! isset($data['affiliate_code'])) {
            $data['affiliate_code'] = $request->cookie('affiliate_ref');
        }

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
