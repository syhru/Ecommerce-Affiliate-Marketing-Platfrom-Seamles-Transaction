<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        // 1. Drop old constraint first
        DB::statement('ALTER TABLE affiliate_profiles DROP CONSTRAINT IF EXISTS affiliate_profiles_status_check');

        // 2. Convert any existing 'suspended' rows to 'inactive' BEFORE adding new constraint
        DB::table('affiliate_profiles')->where('status', 'suspended')->update(['status' => 'inactive']);

        // 3. Add new constraint with: pending, active, rejected, inactive
        DB::statement("ALTER TABLE affiliate_profiles ADD CONSTRAINT affiliate_profiles_status_check CHECK (status::text = ANY (ARRAY['pending'::character varying, 'active'::character varying, 'rejected'::character varying, 'inactive'::character varying]::text[]))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE affiliate_profiles DROP CONSTRAINT IF EXISTS affiliate_profiles_status_check');
        DB::table('affiliate_profiles')->where('status', 'inactive')->update(['status' => 'suspended']);
        DB::statement("ALTER TABLE affiliate_profiles ADD CONSTRAINT affiliate_profiles_status_check CHECK (status::text = ANY (ARRAY['pending'::character varying, 'active'::character varying, 'suspended'::character varying]::text[]))");
    }
};
