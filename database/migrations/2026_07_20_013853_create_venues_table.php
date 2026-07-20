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
        Schema::create('venues', function (Blueprint $table) {
            /*
             * Upstream identifiers are signed: phish.net uses negative values as
             * sentinels (artistid -1 marks a non-Phish performance), so none of
             * the id columns mirrored from the API may be unsigned.
             */
            $table->bigInteger('venueid')->primary();
            $table->string('venuename')->index();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('venues');
    }
};
