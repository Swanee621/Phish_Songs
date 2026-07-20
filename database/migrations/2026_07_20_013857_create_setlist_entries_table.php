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
        Schema::create('setlist_entries', function (Blueprint $table) {
            $table->bigInteger('uniqueid')->primary();
            $table->bigInteger('showid')->index();
            $table->bigInteger('songid')->nullable()->index();

            /*
             * The song name and slug are denormalized onto the entry because the
             * upstream setlist row is authoritative for how a song was billed at
             * that show, and not every performed song appears in the song catalog.
             */
            $table->string('song');
            $table->string('slug')->index();

            $table->string('set')->index();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedTinyInteger('transition')->default(0);
            $table->string('trans_mark')->nullable();
            $table->text('footnote')->nullable();
            $table->boolean('isjam')->default(false);
            $table->boolean('isreprise')->default(false);
            $table->boolean('isjamchart')->default(false);
            $table->text('jamchart_description')->nullable();
            $table->string('tracktime')->nullable();
            $table->integer('gap')->nullable();
            $table->boolean('is_original')->default(false);

            /*
             * phish.net uses artistid -1 for performances by other artists, so
             * this column must be signed.
             */
            $table->bigInteger('artistid')->default(1)->index();
            $table->timestamps();

            $table->index(['showid', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('setlist_entries');
    }
};
