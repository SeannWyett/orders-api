<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Models\Product;
use App\Models\User;
use App\Enums\Role;
use Illuminate\Support\Facades\Mail;
use App\Mail\LowStockAlert;


class SendLowStockAlert implements ShouldQueue
{
    use Queueable;
    
    public int $tries = 3;
    public array $backoff = [10, 30, 60];

    /**
     * Create a new job instance.
     */
    public function __construct(public int $productId) 
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $product = Product::find($this->productId);

        // re-check at run time: stock may have been restocked or cancelled back up
        if (! $product || $product->stock >= 5) {
            return;
        }

        $staff = User::where('role', Role::STAFF)->get();

        foreach ($staff as $member) {
            Mail::to($member->email)->send(new LowStockAlert($product));
        }
    }
}
