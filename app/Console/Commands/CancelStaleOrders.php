<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Actions\CancelOrderAction;
use App\Models\Order;
use App\Enums\OrderStatus;
use App\Exceptions\OrderNotCancellableException;

#[Signature('orders:cancel-stale')]
#[Description('Command description')]
class CancelStaleOrders extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CancelOrderAction $action): int
    {
        $cancelled = 0;

        Order::where('status', OrderStatus::PENDING)
            ->where('created_at', '<', now()->subMinutes(30))
            ->eachById(function (Order $order) use ($action, &$cancelled) {
                try {
                    $action->execute($order);
                    $cancelled++;
                } catch (OrderNotCancellableException) {}
            });

        $this->info("Cancelled {$cancelled} stale order(s).");

        return self::SUCCESS;
    }
}
