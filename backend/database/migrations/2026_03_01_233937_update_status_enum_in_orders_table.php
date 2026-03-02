<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Drop the existing Postgres check constraint on the status column
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check');

        // 2. Add the new constraint with 'paid' included
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status::text = ANY (ARRAY['pending'::character varying, 'verified'::character varying, 'paid'::character varying, 'processing'::character varying, 'shipped'::character varying, 'completed'::character varying, 'cancelled'::character varying]::text[]))");

        // Note: For pure Postgres enum types we would use ALTER TYPE,
        // but Laravel implicitly creates check constraints for enum columns when using Blueprint's enum().
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status::text = ANY (ARRAY['pending'::character varying, 'verified'::character varying, 'processing'::character varying, 'shipped'::character varying, 'completed'::character varying, 'cancelled'::character varying]::text[]))");
    }
};
