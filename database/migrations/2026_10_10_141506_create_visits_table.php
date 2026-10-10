<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->uuid('visitor_id');
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();

            /*
             * Kept separately so a visit still counts towards a country when
             * the city lookup came back empty.
             */
            $table->char('country_code', 2)->nullable();
            $table->string('path', 191);
            $table->timestamp('visited_at');

            $table->index(['visited_at', 'location_id']);
            $table->index(['visitor_id', 'visited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
