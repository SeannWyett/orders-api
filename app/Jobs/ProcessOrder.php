<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Models\Order;
use App\Enums\OrderStatus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use App\Mail\SendOrderConfirmation;
use Throwable;

class ProcessOrder implements ShouldQueue
{
    use Queueable;


    public int $tries = 3;
    public array $backoff = [10, 30, 60];
    /**
     * Create a new job instance.
     */
    public function __construct(public int $orderId) {}
    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $order = Order::findorFail($this->orderId);

        if (in_array($order->status, [OrderStatus::COMPLETED, OrderStatus::CANCELLED,OrderStatus::FAILED])) {
            return;
        }

        $order->update(['status' => OrderStatus::PROCESSING]);

        $order->load('items.product');
        $path = "invoices/{$order->order_number}.pdf";
        Storage::put($path, $this->invoiceText($order));

        $order->update([
            'invoice_path' => $path,
            'status' => OrderStatus::COMPLETED,
            'processed_at' => now(),
        ]);

        Mail::to($order->customer_email)->send(new SendOrderConfirmation($order));
    }

    public function failed(Throwable $e): void 
    {
        Order::find($this->orderId)->update([
            'status' => OrderStatus::FAILED,
            'failure_reason' => $e->getMessage(),
        ]);
    }

    private function invoiceText(Order $order): string
    {
        $lines = ["INVOICE {$order->order_number}", "Customer: {$order->customer_name}", ''];
        foreach ($order->items as $item) {
            $lines[] = "{$item->product->name} x {$item->quantity} @ {$item->unit_price} = {$item->subtotal}";
        }
        $lines[] = '';
        $lines[] = "TOTAL: {$order->total}";

        return implode("\n", $lines);
    }


}
