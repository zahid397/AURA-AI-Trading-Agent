<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->whereHas('tradeProposal', fn ($q) => $q->where('user_id', $request->user()->id))
            ->with('legs')
            ->latest('created_at')
            ->paginate((int) $request->integer('per_page', 25));

        return OrderResource::collection($orders)->response();
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOwnership($request, $order);

        $order->load(['legs', 'trades']);

        return OrderResource::make($order)->response();
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOwnership($request, $order);

        if (! in_array($order->status, [OrderStatus::New, OrderStatus::Accepted, OrderStatus::PartiallyFilled], true)) {
            throw new ApiException('ORDER_NOT_CANCELLABLE', "Order cannot be cancelled from status {$order->status->value}.", 409);
        }

        $order->update(['status' => OrderStatus::Cancelled]);

        return OrderResource::make($order->fresh('legs'))->response();
    }

    private function authorizeOwnership(Request $request, Order $order): void
    {
        if ($order->tradeProposal->user_id !== $request->user()->id) {
            throw new ApiException('FORBIDDEN', 'You do not have access to this order.', 403);
        }
    }
}
