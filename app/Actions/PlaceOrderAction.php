<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Exceptions\InsufficientStockException;
use App\Jobs\ProcessOrder;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;


class PlaceOrderAction
{
    /**
     * Create a new class instance.
     */
    public function execute(array $data): Order
    {
        $order = DB::transaction(function() use ($data) {
            $quantities = collect($data['items'])
                ->groupBy('product_id')
                ->map(fn ($rows) => (int) $rows->sum('quantity'));

            $products = Product::whereIn('id', $quantities->keys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($quantities as $productId => $qty) {
                $product = $products[$productId];
                if ($product->stock < $qty) {
                    throw new InsufficientStockException($product->name);
                }
            }

            $order = Order::create([
                'order_number' => 'TEMP-' .Str::uuid(),
                'customer_name' => $data['customer_name'],
                'customer_email' => $data['customer_email'],
                'status' => OrderStatus::PENDING,
                'total' => 0,
            ]);

            $total = '0.00';

            foreach ($quantities as $productId => $qty) {
                $product = $products[$productId];
                $subtotal = bcmul($product->price, (string) $qty, 2);

                $product->decrement('stock', $qty);
                $order->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $qty,
                    'unit_price' => $product->price,
                    'subtotal' => $subtotal
                ]);
                $total = bcadd($total, $subtotal, 2);
            }

            $order->update([
                'order_number' => sprintf('FC-%s-%04d', now()->format('Ymd'), $order->id),
                'total' => $total,
            ]);

            return $order;
        });

        ProcessOrder::dispatch($order->id);

        return $order->load('items.product');
    }
}