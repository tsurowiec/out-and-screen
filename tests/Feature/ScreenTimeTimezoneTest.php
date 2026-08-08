<?php

namespace Tests\Feature;

use App\Models\ScreenTimeEntry;
use App\Models\User;
use App\Support\DayTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The server clock is UTC; everything the household sees is Europe/Warsaw.
 */
class ScreenTimeTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_app_runs_in_the_household_timezone(): void
    {
        $this->assertSame('Europe/Warsaw', config('app.timezone'));
        $this->assertSame('Europe/Warsaw', now()->timezone->getName());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function instants(): array
    {
        return [
            // Summer time: Warsaw is UTC+2.
            'summer evening' => ['2026-08-08 20:00', '2026-08-08', '22:00'],
            // Winter: UTC+1.
            'winter evening' => ['2026-01-15 20:00', '2026-01-15', '21:00'],
            // Late enough in UTC to already be tomorrow locally.
            'after local midnight' => ['2026-08-08 23:30', '2026-08-09', '01:30'],
        ];
    }

    #[DataProvider('instants')]
    public function test_a_session_is_logged_at_the_local_wall_clock(string $utc, string $localDate, string $localTime): void
    {
        $this->freezeAtUtc($utc);

        Volt::actingAs(User::factory()->create())
            ->test('screen-time.dashboard')
            ->set('type', 'tv')
            ->call('add', 30);

        $entry = ScreenTimeEntry::sole();

        $this->assertSame($localTime, $entry->started_at->format('H:i'));
        $this->assertSame($localDate, $entry->started_at->toDateString());
    }

    public function test_the_day_boundary_follows_local_time(): void
    {
        // 23:30 UTC is already 01:30 the next morning in Warsaw, so "today" has
        // rolled over even though the server's date has not.
        $this->freezeAtUtc('2026-08-08 23:30');

        $this->assertSame('2026-08-09', today()->toDateString());

        $user = User::factory()->create();
        Volt::actingAs($user)->test('screen-time.dashboard')->call('add', 30);

        // The entry lands on the 9th, before the 6am chart window opens, so it
        // counts toward that day's total without being drawn.
        $component = Volt::actingAs($user)->test('screen-time.dashboard');
        $this->assertSame('2026-08-09', $component->instance()->today->day->toDateString());
        $this->assertSame(30, $component->instance()->today->totalMinutes);
        $this->assertSame([], $component->instance()->today->segments);
    }

    public function test_the_chart_window_is_local_time(): void
    {
        // 12:00 UTC is 14:00 in Warsaw — halfway across a 06:00–22:00 window.
        $this->freezeAtUtc('2026-08-08 12:00');

        $entry = ScreenTimeEntry::factory()->make(['minutes' => 60, 'started_at' => now()]);
        $timeline = DayTimeline::build(today(), collect([$entry]));

        $this->assertSame('14:00', $entry->started_at->format('H:i'));
        $this->assertSame(50.0, $timeline->segments[0]['left']);
    }

    public function test_a_running_session_is_judged_in_local_time(): void
    {
        $this->freezeAtUtc('2026-08-08 12:00');

        $user = User::factory()->create();
        Volt::actingAs($user)->test('screen-time.dashboard')->call('add', 60);

        // Half an hour later it is still running; two hours later it is not.
        $this->freezeAtUtc('2026-08-08 12:30');
        $this->assertNotNull(Volt::actingAs($user)->test('screen-time.dashboard')->instance()->activeEntry);

        $this->freezeAtUtc('2026-08-08 14:00');
        $this->assertNull(Volt::actingAs($user)->test('screen-time.dashboard')->instance()->activeEntry);
    }

    /**
     * Freeze the clock at an instant expressed in the server's UTC terms.
     */
    private function freezeAtUtc(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC')->setTimezone(config('app.timezone')));
    }
}
