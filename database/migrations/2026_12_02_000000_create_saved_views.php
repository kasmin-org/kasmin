<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved table views per user, optionally shared (AD11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('page', 64);
            $table->string('name', 80);
            $table->boolean('shared')->default(false);
            $table->json('state');
            $table->timestamps();
            $table->index(['page', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
