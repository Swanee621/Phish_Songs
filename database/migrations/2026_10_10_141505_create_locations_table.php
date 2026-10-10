<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();

            /*
             * One hash over every identifying column stands in for a composite
             * unique key: SQLite and MySQL disagree on whether NULLs in a
             * composite unique collide, and most of these columns are nullable.
             */
            $table->string('key', 40)->unique();
            $table->unsignedBigInteger('geoname_id')->nullable()->index();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->string('region_code', 10)->nullable();
            $table->char('country_code', 2)->nullable()->index();
            $table->string('country')->nullable();
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->unsignedSmallInteger('accuracy_radius')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
