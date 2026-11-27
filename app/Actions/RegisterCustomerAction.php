<?php

namespace App\Actions;

use App\Jobs\SendRegisterEmail;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RegisterCustomerAction
{
    /**
     * Create a new class instance.
     */
    public function __construct() {}

    public function execute(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $email = strtolower($data['email']);

            $user = User::create([
                'name' => $data['name'],
                'email' => $email,
                'password' => $data['password']
            ]);

            Order::whereNull('user_id')
                ->where('customer_email', $email)
                ->update(['user_id' => $user->id]);

                
            $token = $user->createToken('api-token')->plainTextToken;
                
            DB::afterCommit(function () use ($user) {
                SendRegisterEmail::dispatch($user);
            });

            return [$user, $token];
        });
    }
}
