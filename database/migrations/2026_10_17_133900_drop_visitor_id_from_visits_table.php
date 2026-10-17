<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Visits are no longer tied to a browser. The id the cookie carried was
 * meant to notice one browser turning up in two cities, but it kept linking
 * people who had nothing to do with each other, so each visit now stands on
 * its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropIndex(['visitor_id', 'visited_at']);
            $table->dropColumn('visitor_id');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->uuid('visitor_id')->nullable()->after('id');
            $table->index(['visitor_id', 'visited_at']);
        });
    }
};
