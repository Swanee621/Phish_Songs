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
        Schema::create('shows', function (Blueprint $table) {
            $table->bigInteger('showid')->primary();
            $table->date('showdate')->index();
            $table->unsignedSmallInteger('showyear')->index();
            $table->bigInteger('venueid')->nullable()->index();
            $table->bigInteger('tourid')->nullable()->index();

            /*
             * phish.net uses artistid -1 for shows by other artists, so this
             * column must be signed.
             */
            $table->bigInteger('artistid')->default(1)->index();
            $table->string('permalink')->nullable();
            $table->text('setlistnotes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shows');
    }
};
