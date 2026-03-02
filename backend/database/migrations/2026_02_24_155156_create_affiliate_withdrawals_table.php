<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  /**
   * Run the migrations.
   */
  public function up(): void {
    Schema::create('affiliate_withdrawals', function (Blueprint $table) {
      $table->id();
      $table->foreignId('affiliate_id')->constrained('users')->onDelete('cascade');
      $table->decimal('amount', 15, 2);
      $table->enum('status', ['pending', 'processing', 'completed', 'rejected'])->default('pending');
      $table->string('bank_name', 100);
      $table->string('bank_account_number', 50);
      $table->string('bank_account_holder', 255);
      $table->timestamp('processed_at')->nullable();
      $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
      $table->text('rejection_reason')->nullable();
      $table->text('notes')->nullable();
      $table->timestamps();

      $table->index('affiliate_id');
      $table->index('status');
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void {
    Schema::dropIfExists('affiliate_withdrawals');
  }
};
