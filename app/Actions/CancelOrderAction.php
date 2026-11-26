<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Models\Product;
use App\Enums\OrderStatus;
use App\Exceptions\OrderNotCancellableException;

class CancelOrderAction
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public function execute($order)
    {
        return DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->id)->lockForupdate()->firstorFail();

            if ($locked->status !== OrderStatus::PENDING) {
                throw new OrderNotCancellableException('Only pending orders can be cancelled.');
            }

            $items = $locked->items()->orderBy('product_id')->get();
            Product::whereIn('id', $items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get();

            foreach ($items as $item) {
                Product::whereKey($item->product_id)->increment('stock', $item->quantity);
            }

            $locked->update(['status' => OrderStatus::CANCELLED]);

            return $locked->load('items.product');
        });
    }
}
