<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;

class Order extends Model
{
    /** @use HasFactory<\Database\Factories\OrderFactory> */
    use HasFactory;

    protected $fillable = [
        'order_number',
        'user_id',
        'customer_name',
        'customer_email',
        'status',
        'total',
        'invoice_path',
        'processed_at',
        'failure_reason',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'total' => 'decimal:2',
        'processed_at' => 'datetime',
    ];

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isStaff()
            ? $query
            : $query->where('user_id', $user->id);
    }

    public function items()
    {
        return $this->hasMany(OrderItems::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getRoutekeyName(): string
    {
        return 'order_number';
    }
}
