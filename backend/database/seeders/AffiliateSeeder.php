<?php

namespace Database\Seeders;

use App\Models\AffiliateProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AffiliateSeeder extends Seeder {
  public function run(): void {
    $affiliates = [
      ['name' => 'Budi Santoso',    'email' => 'budi@example.com'],
      ['name' => 'Rina Permata',    'email' => 'rina@example.com'],
      ['name' => 'Joko Widodo',     'email' => 'joko@example.com'],
      ['name' => 'Siti Rahayu',     'email' => 'siti@example.com'],
      ['name' => 'Agus Salim',      'email' => 'agus@example.com'],
      ['name' => 'Dewi Kurniawan',  'email' => 'dewi@example.com'],
      ['name' => 'Hendra Wijaya',   'email' => 'hendra@example.com'],
      ['name' => 'Mega Pratiwi',    'email' => 'mega@example.com'],
      ['name' => 'Bambang Susilo',  'email' => 'bambang@example.com'],
      ['name' => 'Putri Handayani', 'email' => 'putri@example.com'],
    ];

    $admin = User::where('role', 'admin')->first();

    foreach ($affiliates as $data) {
      $user = User::firstOrCreate(
        ['email' => $data['email']],
        [
          'name'              => $data['name'],
          'password'          => Hash::make('password'),
          'role'              => 'affiliate',
          'email_verified_at' => now(),
        ]
      );

      AffiliateProfile::firstOrCreate(
        ['user_id' => $user->id],
        [
          'referral_code'       => AffiliateProfile::generateReferralCode(),
          'commission_rate'     => 10.00,
          'balance'             => rand(0, 2000000),
          'total_earned'        => rand(500000, 10000000),
          'status'              => 'active',
          'approved_at'         => now()->subDays(rand(10, 90)),
          'approved_by'         => $admin?->id,
          'bank_name'           => collect(['BCA', 'BRI', 'BNI', 'Mandiri'])->random(),
          'bank_account_number' => '1234' . rand(100000, 999999),
          'bank_account_holder' => $data['name'],
        ]
      );
    }

    $this->command->info('10 affiliate accounts seeded. Password: password');
  }
}
