<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The app tracks one kid, shared by everyone who logs in: both parents and the
 * kid see the same entries. Ownership moves off the entries themselves and is
 * replaced by a role that says who is allowed to change them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('parent')->after('email');
        });

        // Entries are no longer owned by a user. The column is kept as a record
        // of who logged the entry, and no longer takes the entry down with the
        // account.
        Schema::table('screen_time_entries', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('screen_time_entries', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
        });

        Schema::table('screen_time_entries', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        // Allowance overrides now apply to the household, so only one can exist
        // per day. Collapse any duplicates before the new constraint lands.
        $keep = DB::table('screen_time_limit_overrides')
            ->selectRaw('MAX(id) as id')
            ->groupBy('date')
            ->pluck('id');

        DB::table('screen_time_limit_overrides')->whereNotIn('id', $keep)->delete();

        Schema::table('screen_time_limit_overrides', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['user_id', 'date']);
            $table->dropColumn('user_id');
        });

        Schema::table('screen_time_limit_overrides', function (Blueprint $table) {
            $table->unique('date');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });

        Schema::table('screen_time_limit_overrides', function (Blueprint $table) {
            $table->dropUnique(['date']);
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unique(['user_id', 'date']);
        });
    }
};
