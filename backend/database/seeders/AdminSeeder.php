<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->warn('Privileged seeding is disabled. Use superadmin:provision internally.');
    }
}
