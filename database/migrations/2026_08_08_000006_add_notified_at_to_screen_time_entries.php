<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screen_time_entries', function (Blueprint $table) {
            // When the "session over" push went out. Kept so a retried or
            // duplicated job can't notify twice for the same session.
            $table->timestamp('notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('screen_time_entries', function (Blueprint $table) {
            $table->dropColumn('notified_at');
        });
    }
};
