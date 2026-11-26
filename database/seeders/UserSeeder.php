<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Enums\Role;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Staff1',
            'email' => 'staff1@gmail.com',
            'role' => Role::STAFF,
        ]);

        User::factory()->create([
            'name' => 'Staff2',
            'email' => 'staff2@gmail.com',
            'role' => Role::STAFF,
        ]);

        User::factory()->create([
            'name' => 'Customer1',
            'email' => 'customer1@gmail.com',
        ]);

        User::factory()->create([
            'name' => 'Customer2',
            'email' => 'customer2@gmail.com',
        ]);

        User::factory()->create([
            'name' => 'Customer3',
            'email' => 'customer3@gmail.com',
        ]);
    }
}
