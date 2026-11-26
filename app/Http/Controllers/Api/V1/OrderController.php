<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\StoreOrderRequest;
use App\Actions\PlaceOrderAction;
use App\Http\Resources\OrderResource;
use App\Enums\OrderStatus;
use Illuminate\Validation\Rule;
use App\Models\Order;
use App\Actions\CancelOrderAction;

class OrderController extends Controller
{
    public function store(StoreOrderRequest $request, PlaceOrderAction $action)
    {   
        $data = $request->validated();
        
        if ($user = $request->user('sanctum')) {
            $data['customer_name'] = $user->name;
            $data['customer_email'] = strtolower($user->email);
        }
        $order = $action->execute($data);

        return (new OrderResource($order))
            ->response()
            ->setStatusCode(201);
    }

    public function index(Request $request)
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'customer_email' => ['nullable', 'email'], 
        ]);

        $user = $request->user();

        $orders = Order::visibleTo($user)
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($user->isStaff() && $request->query('customer_email'), 
                fn ($q, $e) => $q->where('customer_email', strtoLower($request->query('customer_email'))))
            ->latest('id')
            ->paginate(15);
        
        return OrderResource::collection($orders);
    }

    public function show(Request $request, string $orderNumber) 
    {
        $order = Order::visibleTo($request->user())
            ->where('order_number', $orderNumber)
            ->with('items.product')
            ->firstOrFail();          

        return new OrderResource($order);
    }

    public function cancel(Request $request, string $orderNumber, CancelOrderAction $action)
    {
        $order = Order::visibleTo($request->user())
            ->where('order_number', $orderNumber)
            ->firstOrFail();

        return new OrderResource($action->execute($order));
    }
}
