<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time spent out on a trip. Like screen time, trips belong to the household
 * rather than to the user who logged them; `user_id` is only a record of who
 * typed it in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->unsignedSmallInteger('minutes');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_entries');
    }
};
