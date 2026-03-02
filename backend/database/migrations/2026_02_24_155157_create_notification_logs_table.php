<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  /**
   * Run the migrations.
   */
  public function up(): void {
    Schema::create('notification_logs', function (Blueprint $table) {
      $table->id();
      $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
      $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
      $table->enum('channel', ['telegram'])->default('telegram');
      $table->string('recipient', 255);
      $table->string('message_type', 50);
      $table->text('message_content');
      $table->timestamp('sent_at')->nullable();
      $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
      $table->text('error_message')->nullable();
      $table->timestamps();

      $table->index('order_id');
      $table->index('user_id');
      $table->index('status');
      $table->index('channel');
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void {
    Schema::dropIfExists('notification_logs');
  }
};
