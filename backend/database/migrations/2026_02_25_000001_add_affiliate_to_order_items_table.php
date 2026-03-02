<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('affiliate_code', 20)->nullable()->after('subtotal');
            $table->decimal('commission_amount', 15, 2)->default(0)->after('affiliate_code');
        });

        // Add shipping_cost to orders if not already present
        if (! Schema::hasColumn('orders', 'shipping_cost')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->decimal('shipping_cost', 15, 2)->default(0)->after('commission_amount');
            });
        }
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['affiliate_code', 'commission_amount']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('shipping_cost');
        });
    }
};
