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
        Schema::create('songs', function (Blueprint $table) {
            $table->bigInteger('songid')->primary();
            $table->string('song');
            $table->string('slug')->unique();
            $table->string('artist')->nullable()->index();
            $table->unsignedInteger('times_played')->default(0)->index();
            $table->string('debut')->nullable();
            $table->string('last_played')->nullable();
            $table->integer('gap')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('songs');
    }
};
