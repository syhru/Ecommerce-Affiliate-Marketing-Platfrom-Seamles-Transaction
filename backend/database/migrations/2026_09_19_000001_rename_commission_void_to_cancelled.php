<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WS-03 / PA-F-08+PA-F-09 — commission lifecycle vocabulary.
 *
 * The historical create migration (2026_02_24_155156) is not re-executed by a
 * database that already ran it, so this migration carries the WS-03 schema
 * delta forward for those existing deployments:
 *
 *  1. `cancelled_at` — the column AffiliateService::cancelCommission() writes.
 *     Added only when missing, so a fresh database (which already declares it
 *     in the create migration) is not double-altered.
 *  2. the status CHECK constraint — the create migration leaves the old
 *     vocabulary in place on PostgreSQL, so it is re-declared here with the
 *     new `cancelled` value.
 *
 * The pre-WS03 vocabulary was exactly `pending, earned, withdrawn`. No code
 * path ever produced a `void` commission, so there is no data to translate.
 */
return new class extends Migration {
  /**
   * Run the migrations.
   */
  public function up(): void {
    if (DB::getDriverName() !== 'pgsql') {
      // SQLite derives its CHECK constraint from the create migration's enum
      // list, which already carries the new vocabulary and `cancelled_at`.
      // Nothing to alter.
      return;
    }

    // Existing deployments never re-run the create migration; fresh ones do
    // and already carry the column. Only add what is missing.
    if (! Schema::hasColumn('affiliate_commissions', 'cancelled_at')) {
      Schema::table('affiliate_commissions', function (Blueprint $table): void {
        $table->timestamp('cancelled_at')->nullable()->after('earned_at');
      });
    }

    DB::statement('ALTER TABLE affiliate_commissions DROP CONSTRAINT IF EXISTS affiliate_commissions_status_check');
    DB::statement("ALTER TABLE affiliate_commissions ADD CONSTRAINT affiliate_commissions_status_check CHECK (status::text = ANY (ARRAY['pending'::character varying, 'earned'::character varying, 'cancelled'::character varying, 'withdrawn'::character varying]::text[]))");
  }

  /**
   * Reverse the migrations — restore the exact pre-WS03 vocabulary.
   */
  public function down(): void {
    if (DB::getDriverName() !== 'pgsql') {
      return;
    }

    DB::statement('ALTER TABLE affiliate_commissions DROP CONSTRAINT IF EXISTS affiliate_commissions_status_check');
    DB::statement("ALTER TABLE affiliate_commissions ADD CONSTRAINT affiliate_commissions_status_check CHECK (status::text = ANY (ARRAY['pending'::character varying, 'earned'::character varying, 'withdrawn'::character varying]::text[]))");
  }
};
