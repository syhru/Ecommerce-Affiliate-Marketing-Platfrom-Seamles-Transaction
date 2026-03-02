<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  /**
   * Run the migrations.
   */
  public function up(): void {
    Schema::create('products', function (Blueprint $table) {
      $table->id();
      $table->string('name');
      $table->string('slug')->unique();
      $table->string('brand', 100);
      $table->string('type', 100);
      $table->enum('category', ['motor', 'shockbreaker']);
      $table->text('description')->nullable();
      $table->text('technical_specs')->nullable();
      $table->decimal('price', 15, 2);
      $table->integer('stock')->default(0);
      $table->string('master_video_url', 500)->nullable();
      $table->string('thumbnail_url', 500)->nullable();
      $table->boolean('is_active')->default(true);
      $table->timestamps();
      $table->softDeletes();

      $table->index('category');
      $table->index('is_active');
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void {
    Schema::dropIfExists('products');
  }
};
