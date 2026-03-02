<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder {
  public function run(): void {
    User::firstOrCreate(
      ['email' => 'admin@tdr-hpz.com'],
      [
        'name'              => 'Super Admin',
        'password'          => Hash::make('admin12345'),
        'role'              => 'admin',
        'email_verified_at' => now(),
      ]
    );

    $this->command->info('Admin user created: admin@tdr-hpz.com / admin12345');
  }
}
