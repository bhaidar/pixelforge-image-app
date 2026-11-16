<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pixelforge_id')->nullable()->unique();
            // pending (not yet accepted by Pixelforge), queued, processing, succeeded, failed
            $table->string('status')->default('pending')->index();
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');
            $table->json('rates')->nullable();
            $table->json('usage')->nullable();
            $table->bigInteger('estimated_min_nanos');
            $table->bigInteger('estimated_max_nanos');
            $table->bigInteger('provider_cost_nanos')->nullable();
            $table->bigInteger('charge_nanos')->nullable();
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
