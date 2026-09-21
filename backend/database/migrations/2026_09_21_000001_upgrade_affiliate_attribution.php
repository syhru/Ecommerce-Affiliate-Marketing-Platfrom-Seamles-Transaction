<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WS-04: canonical affiliate attribution upgrade for existing deployments.
 *
 * Fresh databases already build the canonical `affiliate_profiles` vocabulary
 * (`pending, active, rejected, inactive`) and the new `affiliate_clicks` columns
 * in their create migrations. This migration only carries existing PostgreSQL
 * deployments forward:
 *
 *  1. legacy `suspended` profiles become `inactive`;
 *  2. the PostgreSQL status check is re-declared with the canonical vocabulary;
 *  3. `affiliate_clicks` gains the referral / visitor / landing columns needed
 *     for deduped tracking, backfilling old rows so every click keeps a
 *     non-null identity.
 *
 * Rollback removes attribution columns and dedupe state, losing their metadata.
 * It cannot distinguish converted `suspended` from genuine `inactive` profiles;
 * see down() for the retained canonical status vocabulary.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->upgradeProfileStatusVocabulary();
        $this->upgradeClickTracking();
        $this->upgradeClickDedupeState();
    }

    private function upgradeProfileStatusVocabulary(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE affiliate_profiles DROP CONSTRAINT IF EXISTS affiliate_profiles_status_check');

        DB::table('affiliate_profiles')
            ->where('status', 'suspended')
            ->update(['status' => 'inactive']);

        DB::statement("ALTER TABLE affiliate_profiles ADD CONSTRAINT affiliate_profiles_status_check CHECK (status::text = ANY (ARRAY['pending'::character varying, 'active'::character varying, 'rejected'::character varying, 'inactive'::character varying]::text[]))");
    }

    private function upgradeClickTracking(): void
    {
        if (! Schema::hasColumn('affiliate_clicks', 'referral_code')) {
            Schema::table('affiliate_clicks', function (Blueprint $table) {
                $table->string('referral_code', 20)->nullable()->after('affiliate_id');
                $table->string('visitor_token', 128)->nullable()->after('referral_code');
                $table->string('landing_url', 1000)->nullable()->after('visitor_token');
            });

            // Pre-WS-04 clicks have no browser identity; preserve them as legacy rows.
            DB::table('affiliate_clicks')->whereNull('referral_code')->update(['referral_code' => 'legacy']);
            DB::table('affiliate_clicks')->whereNull('visitor_token')->update(['visitor_token' => 'legacy']);
        }

        if (! Schema::hasIndex('affiliate_clicks', 'affiliate_clicks_dedupe_index')) {
            $legacyIndex = 'affiliate_clicks_affiliate_id_visitor_token_clicked_at_index';
            if (Schema::hasIndex('affiliate_clicks', $legacyIndex)) {
                Schema::table('affiliate_clicks', function (Blueprint $table) use ($legacyIndex) {
                    $table->renameIndex($legacyIndex, 'affiliate_clicks_dedupe_index');
                });
            } else {
                Schema::table('affiliate_clicks', function (Blueprint $table) {
                    $table->index(['affiliate_id', 'visitor_token', 'clicked_at'], 'affiliate_clicks_dedupe_index');
                });
            }
        }
    }

    private function upgradeClickDedupeState(): void
    {
        if (Schema::hasTable('affiliate_click_dedupe_states')) {
            return;
        }

        Schema::create('affiliate_click_dedupe_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained('users')->cascadeOnDelete();
            $table->string('visitor_token', 128);
            $table->timestamp('last_clicked_at')->nullable();
            $table->timestamps();
            $table->unique(['affiliate_id', 'visitor_token']);
        });

        DB::table('affiliate_clicks')
            ->select('affiliate_id', 'visitor_token')
            ->selectRaw('MAX(clicked_at) as last_clicked_at')
            ->whereNotNull('visitor_token')
            ->groupBy('affiliate_id', 'visitor_token')
            ->orderBy('affiliate_id')
            ->chunk(500, function ($clicks): void {
                foreach ($clicks as $click) {
                    DB::table('affiliate_click_dedupe_states')->insert([
                        'affiliate_id' => $click->affiliate_id,
                        'visitor_token' => $click->visitor_token,
                        'last_clicked_at' => $click->last_clicked_at,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasTable('affiliate_click_dedupe_states')) {
            Schema::drop('affiliate_click_dedupe_states');
        }

        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasColumn('affiliate_clicks', 'referral_code')) {
            return;
        }

        foreach (['affiliate_clicks_dedupe_index', 'affiliate_clicks_affiliate_id_visitor_token_clicked_at_index'] as $index) {
            if (Schema::hasIndex('affiliate_clicks', $index)) {
                Schema::table('affiliate_clicks', function (Blueprint $table) use ($index) {
                    $table->dropIndex($index);
                });
            }
        }

        Schema::table('affiliate_clicks', function (Blueprint $table) {
            $table->dropColumn(['referral_code', 'visitor_token', 'landing_url']);
        });

        // Intentionally not restoring `suspended`: after this migration runs,
        // converted rows are indistinguishable from profiles that were genuinely
        // set `inactive`, so a rollback that mapped inactive -> suspended would
        // re-suspend profiles an admin deliberately deactivated. The canonical
        // vocabulary stays; the historical lossy step is documented in the
        // WS-04 result file.
    }
};
