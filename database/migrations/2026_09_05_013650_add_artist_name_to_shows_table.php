<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who played a show, for the ones Phish did not.
     *
     * phish.net carries the guest appearances the band turns up at under
     * `artistid = -1`, with the host act's name in `artist_name`. Without it
     * stored, such a show is only distinguishable from a Phish show by a
     * sentinel id, which is not something a page can put on screen.
     */
    public function up(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->string('artist_name')->nullable()->after('artistid');
        });
    }

    public function down(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->dropColumn('artist_name');
        });
    }
};
