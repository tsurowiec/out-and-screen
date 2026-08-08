<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Screen time used to be written while the app ran in UTC; it now works in the
 * household's own timezone. The stored wall-clock values have to move with it,
 * or every existing entry reads two hours early.
 *
 * The conversion is deliberately hard-coded rather than read from config, so it
 * stays a one-off correction of the rows written before the switch.
 */
return new class extends Migration
{
    private const FROM = 'UTC';

    private const TO = 'Europe/Warsaw';

    public function up(): void
    {
        $this->shift(self::FROM, self::TO);
    }

    public function down(): void
    {
        $this->shift(self::TO, self::FROM);
    }

    private function shift(string $from, string $to): void
    {
        DB::table('screen_time_entries')
            ->orderBy('id')
            ->select('id', 'started_at')
            ->chunk(200, function ($entries) use ($from, $to) {
                foreach ($entries as $entry) {
                    DB::table('screen_time_entries')
                        ->where('id', $entry->id)
                        ->update([
                            'started_at' => Carbon::parse($entry->started_at, $from)
                                ->setTimezone($to)
                                ->format('Y-m-d H:i:s'),
                        ]);
                }
            });
    }
};
