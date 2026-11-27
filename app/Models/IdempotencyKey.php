<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Builder;

class IdempotencyKey extends Model
{
    use MassPrunable;

    protected $fillable = ['key','scope','request_hash','response_status','response_body','completed_at'];
    protected $casts = ['completed_at' => 'datetime'];

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDay());
    }
}
