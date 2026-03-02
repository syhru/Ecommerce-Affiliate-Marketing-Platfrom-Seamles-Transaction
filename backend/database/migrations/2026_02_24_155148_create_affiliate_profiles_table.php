<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  /**
   * Run the migrations.
   */
  public function up(): void {
    Schema::create('affiliate_profiles', function (Blueprint $table) {
      $table->id();
      $table->foreignId('user_id')->constrained()->onDelete('cascade')->unique();
      $table->string('referral_code', 20)->unique();
      $table->decimal('commission_rate', 5, 2)->default(10.00);
      $table->decimal('balance', 15, 2)->default(0.00);
      $table->decimal('total_earned', 15, 2)->default(0.00);
      $table->enum('status', ['pending', 'active', 'suspended'])->default('pending');
      $table->timestamp('approved_at')->nullable();
      $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
      $table->string('bank_name', 100)->nullable();
      $table->string('bank_account_number', 50)->nullable();
      $table->string('bank_account_holder', 255)->nullable();
      $table->timestamps();

      $table->index('referral_code');
      $table->index('status');
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void {
    Schema::dropIfExists('affiliate_profiles');
  }
};
