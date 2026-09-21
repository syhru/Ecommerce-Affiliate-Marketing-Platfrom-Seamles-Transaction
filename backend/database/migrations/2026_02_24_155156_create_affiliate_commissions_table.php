<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  /**
   * Run the migrations.
   */
  public function up(): void {
    Schema::create('affiliate_commissions', function (Blueprint $table) {
      $table->id();
      $table->foreignId('order_id')->constrained()->onDelete('cascade');
      $table->foreignId('affiliate_id')->constrained('users')->onDelete('cascade');
      $table->decimal('amount', 15, 2);
      $table->decimal('commission_rate', 5, 2);
      // The commission lifecycle: pending → earned (order completed), with
      // `cancelled` for an order cancelled before completion, and `withdrawn`
      // once paid out by an approved withdrawal (WS-03 §3.2, §6).
      $table->enum('status', ['pending', 'earned', 'cancelled', 'withdrawn'])->default('pending');
      $table->timestamp('earned_at')->nullable();
      $table->timestamp('cancelled_at')->nullable();
      $table->timestamps();

      $table->index('order_id');
      $table->index('affiliate_id');
      $table->index('status');
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void {
    Schema::dropIfExists('affiliate_commissions');
  }
};
