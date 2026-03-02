<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  /**
   * Run the migrations.
   */
  public function up(): void {
    Schema::create('orders', function (Blueprint $table) {
      $table->id();
      $table->string('order_number', 50)->unique();
      $table->foreignId('customer_id')->constrained('users')->onDelete('cascade');
      $table->foreignId('affiliate_id')->nullable()->constrained('users')->nullOnDelete();
      $table->decimal('subtotal', 15, 2);
      $table->decimal('commission_amount', 15, 2)->default(0.00);
      $table->decimal('total_amount', 15, 2);
      $table->enum('status', ['pending', 'verified', 'processing', 'shipped', 'completed', 'cancelled'])->default('pending');
      $table->string('payment_method', 50)->nullable();
      $table->string('midtrans_transaction_id', 255)->nullable();
      $table->string('midtrans_snap_token', 255)->nullable();
      $table->timestamp('payment_verified_at')->nullable();
      $table->text('shipping_address');
      $table->string('shipping_courier', 100)->nullable();
      $table->string('shipping_tracking_number', 100)->nullable();
      $table->timestamp('shipped_at')->nullable();
      $table->timestamp('completed_at')->nullable();
      $table->timestamp('cancelled_at')->nullable();
      $table->text('cancellation_reason')->nullable();
      $table->text('notes')->nullable();
      $table->timestamps();

      $table->index('customer_id');
      $table->index('affiliate_id');
      $table->index('status');
      $table->index('midtrans_transaction_id');
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void {
    Schema::dropIfExists('orders');
  }
};
