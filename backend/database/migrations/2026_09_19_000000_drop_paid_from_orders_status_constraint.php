<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WS-03 / PA-F-02 — canonical order status vocabulary.
 *
 * `verified` is the one canonical status for a successful payment; `paid` is
 * not a runtime status and is removed from the Postgres check constraint so
 * the database can no longer accept it.
 *
 * On non-Postgres drivers (the SQLite used in tests) Laravel does not create
 * a named check constraint for enum() columns, so the migration is a no-op
 * there — the enum() definition in the earlier create migration is the only
 * vocabulary those drivers know, and it never contained 'paid'.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check');

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status::text = ANY (ARRAY['pending'::character varying, 'verified'::character varying, 'processing'::character varying, 'shipped'::character varying, 'completed'::character varying, 'cancelled'::character varying]::text[]))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check');

        // Restores the WS-02 vocabulary, which still included the legacy 'paid' value.
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status::text = ANY (ARRAY['pending'::character varying, 'verified'::character varying, 'paid'::character varying, 'processing'::character varying, 'shipped'::character varying, 'completed'::character varying, 'cancelled'::character varying]::text[]))");
    }
};
