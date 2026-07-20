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
        Schema::create('phishnet_sync_states', function (Blueprint $table) {
            $table->id();

            /*
             * Identifies the upstream resource, e.g. "setlists.year.2026" or "songs".
             */
            $table->string('key')->unique();

            /*
             * Hash of the last imported payload. The upstream API exposes no
             * modified timestamp, so a payload hash is what tells us whether
             * anything actually changed since the previous sync.
             */
            $table->string('hash', 64)->nullable();

            $table->unsignedInteger('row_count')->default(0);
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('phishnet_sync_states');
    }
};
