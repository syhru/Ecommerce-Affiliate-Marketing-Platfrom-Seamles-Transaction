<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  /**
   * Run the migrations.
   */
  public function up(): void {
    Schema::create('affiliate_clicks', function (Blueprint $table) {
      $table->id();
      $table->foreignId('affiliate_id')->constrained('users')->onDelete('cascade');
      $table->string('referral_code', 20);
      $table->string('visitor_token', 128);
      $table->string('landing_url', 1000)->nullable();
      $table->string('ip_address', 45);
      $table->text('user_agent')->nullable();
      $table->string('referrer_url', 500)->nullable();
      $table->timestamp('clicked_at')->useCurrent();

      $table->index('affiliate_id');
      $table->index('clicked_at');
      $table->index(['affiliate_id', 'visitor_token', 'clicked_at'], 'affiliate_clicks_dedupe_index');
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void {
    Schema::dropIfExists('affiliate_clicks');
  }
};
