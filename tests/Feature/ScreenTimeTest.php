<?php

namespace Tests\Feature;

use App\Enums\ScreenType;
use App\Models\ScreenTimeEntry;
use App\Models\ScreenTimeLimitOverride;
use App\Models\User;
use App\Support\DayTimeline;
use App\Support\ScreenTimeLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScreenTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_for_authenticated_users(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Add screen time');
    }

    public function test_quick_add_creates_an_entry_starting_now(): void
    {
        Carbon::setTestNow(today()->setTime(15, 0));
        $user = User::factory()->create();

        Volt::actingAs($user)
            ->test('screen-time.dashboard')
            ->set('type', 'youtube')
            ->call('add', 30)
            ->assertHasNoErrors();

        $entry = $user->recordedScreenTimeEntries()->sole();

        $this->assertSame(ScreenType::Youtube, $entry->type);
        $this->assertSame(30, $entry->minutes);
        $this->assertSame('15:00', $entry->started_at->format('H:i'));
        $this->assertSame('15:30', $entry->endedAt()->format('H:i'));
    }

    public function test_quick_add_rejects_durations_that_are_not_offered(): void
    {
        $user = User::factory()->create();

        Volt::actingAs($user)
            ->test('screen-time.dashboard')
            ->call('add', 999)
            ->assertStatus(422);

        $this->assertSame(0, $user->recordedScreenTimeEntries()->count());
    }

    public function test_entries_can_be_removed(): void
    {
        $user = User::factory()->create();
        $entry = ScreenTimeEntry::factory()->for($user)->create(['started_at' => today()->setTime(9, 0)]);

        Volt::actingAs($user)
            ->test('screen-time.dashboard')
            ->call('remove', $entry->id);

        $this->assertDatabaseMissing('screen_time_entries', ['id' => $entry->id]);
    }

    public function test_every_screen_type_has_a_distinct_colour_label_and_icon(): void
    {
        $types = ScreenType::cases();

        $this->assertSame(
            ['mobile', 'tv', 'playstation', 'computer', 'youtube'],
            array_column($types, 'value'),
        );

        foreach ($types as $type) {
            $this->assertNotEmpty($type->label());
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $type->color());
            $this->assertNotEmpty($type->icon());
        }

        $colors = array_map(fn (ScreenType $type) => $type->color(), $types);
        $this->assertSame($colors, array_unique($colors), 'Screen types must be told apart on the charts.');
    }

    public function test_every_screen_type_is_offered_on_the_dashboard(): void
    {
        $user = User::factory()->create();
        ScreenTimeEntry::factory()->for($user)->create(['started_at' => today()->setTime(9, 0)]);

        $response = $this->actingAs($user)->get('/dashboard')->assertOk();

        foreach (ScreenType::cases() as $type) {
            $response->assertSee($type->label());
            $response->assertSee($type->color(), false);
        }
    }

    public function test_computer_time_can_be_logged(): void
    {
        Carbon::setTestNow(today()->setTime(16, 0));
        $user = User::factory()->create();

        Volt::actingAs($user)
            ->test('screen-time.dashboard')
            ->set('type', 'computer')
            ->call('add', 30)
            ->assertHasNoErrors();

        $this->assertSame(ScreenType::Computer, $user->recordedScreenTimeEntries()->sole()->type);
    }

    public function test_a_running_session_is_shown_as_active(): void
    {
        Carbon::setTestNow(today()->setTime(15, 20));
        $user = User::factory()->create();
        $entry = ScreenTimeEntry::factory()->for($user)->create([
            'type' => ScreenType::Tv,
            'minutes' => 60,
            'started_at' => today()->setTime(15, 0),
        ]);

        $component = Volt::actingAs($user)->test('screen-time.dashboard');

        $this->assertSame($entry->id, $component->instance()->activeEntry?->id);
        $component->assertSee('TV in progress');
    }

    public function test_a_finished_session_is_not_shown_as_active(): void
    {
        Carbon::setTestNow(today()->setTime(15, 20));
        $user = User::factory()->create();
        ScreenTimeEntry::factory()->for($user)->create([
            'minutes' => 15,
            'started_at' => today()->setTime(15, 0),
        ]);

        $component = Volt::actingAs($user)->test('screen-time.dashboard');

        $this->assertNull($component->instance()->activeEntry);
        $component->assertDontSee('in progress');
    }

    public function test_a_session_that_has_not_started_is_not_active(): void
    {
        Carbon::setTestNow(today()->setTime(15, 0));
        $user = User::factory()->create();
        ScreenTimeEntry::factory()->for($user)->create([
            'minutes' => 30,
            'started_at' => today()->setTime(16, 0),
        ]);

        $this->assertNull(Volt::actingAs($user)->test('screen-time.dashboard')->instance()->activeEntry);
    }

    public function test_the_most_recently_started_session_wins_when_they_overlap(): void
    {
        Carbon::setTestNow(today()->setTime(15, 20));
        $user = User::factory()->create();
        ScreenTimeEntry::factory()->for($user)->create(['minutes' => 60, 'started_at' => today()->setTime(15, 0)]);
        $newer = ScreenTimeEntry::factory()->for($user)->create(['minutes' => 60, 'started_at' => today()->setTime(15, 10)]);

        $component = Volt::actingAs($user)->test('screen-time.dashboard');

        $this->assertSame($newer->id, $component->instance()->activeEntry?->id);
    }

    public function test_a_session_logged_by_one_parent_is_visible_to_everyone(): void
    {
        Carbon::setTestNow(today()->setTime(15, 20));
        ScreenTimeEntry::factory()->create(['minutes' => 60, 'started_at' => today()->setTime(15, 0)]);

        foreach ([User::factory()->create(), User::factory()->child()->create()] as $user) {
            $component = Volt::actingAs($user)->test('screen-time.dashboard');

            $this->assertNotNull($component->instance()->activeEntry);
        }
    }

    public function test_logging_a_session_starts_the_countdown(): void
    {
        Carbon::setTestNow(today()->setTime(15, 0));
        $user = User::factory()->create();

        $component = Volt::actingAs($user)->test('screen-time.dashboard');
        $this->assertNull($component->instance()->activeEntry);

        $component->set('type', 'playstation')->call('add', 60);

        $this->assertSame(ScreenType::Playstation, $component->instance()->activeEntry?->type);
        $component->assertSee('PlayStation in progress');
    }

    public function test_a_running_session_can_be_extended(): void
    {
        Carbon::setTestNow(today()->setTime(15, 50));
        $user = User::factory()->create();
        $entry = ScreenTimeEntry::factory()->for($user)->create([
            'minutes' => 60,
            'started_at' => today()->setTime(15, 0),
        ]);

        $component = Volt::actingAs($user)->test('screen-time.dashboard');
        $component->assertSee('Extend by');

        $component->call('extend', 15)->assertHasNoErrors();

        $entry->refresh();

        $this->assertSame(75, $entry->minutes);
        // The start time is untouched, so the session now runs until 16:15.
        $this->assertSame('15:00', $entry->started_at->format('H:i'));
        $this->assertSame('16:15', $entry->endedAt()->format('H:i'));
        // Still running, so the countdown stays on screen with the new time.
        $this->assertSame($entry->id, $component->instance()->activeEntry?->id);
    }

    public function test_extending_twice_keeps_adding_time(): void
    {
        Carbon::setTestNow(today()->setTime(15, 10));
        $user = User::factory()->create();
        $entry = ScreenTimeEntry::factory()->for($user)->create([
            'minutes' => 30,
            'started_at' => today()->setTime(15, 0),
        ]);

        $component = Volt::actingAs($user)->test('screen-time.dashboard');
        $component->call('extend', 5);
        $component->call('extend', 10);

        $this->assertSame(45, $entry->fresh()->minutes);
    }

    public function test_extending_counts_toward_the_daily_total(): void
    {
        Carbon::setTestNow(today()->setTime(15, 10));
        $user = User::factory()->create();
        ScreenTimeEntry::factory()->for($user)->create([
            'minutes' => 30,
            'started_at' => today()->setTime(15, 0),
        ]);

        $component = Volt::actingAs($user)->test('screen-time.dashboard');
        $this->assertSame(30, $component->instance()->today->totalMinutes);

        $component->call('extend', 10);

        $this->assertSame(40, $component->instance()->today->totalMinutes);
    }

    public function test_extending_rejects_a_duration_that_is_not_offered(): void
    {
        Carbon::setTestNow(today()->setTime(15, 10));
        $user = User::factory()->create();
        $entry = ScreenTimeEntry::factory()->for($user)->create([
            'minutes' => 30,
            'started_at' => today()->setTime(15, 0),
        ]);

        Volt::actingAs($user)
            ->test('screen-time.dashboard')
            ->call('extend', 120)
            ->assertStatus(422);

        $this->assertSame(30, $entry->fresh()->minutes);
    }

    public function test_extending_fails_when_no_session_is_running(): void
    {
        Carbon::setTestNow(today()->setTime(15, 40));
        $user = User::factory()->create();
        $entry = ScreenTimeEntry::factory()->for($user)->create([
            'minutes' => 30,
            'started_at' => today()->setTime(15, 0),
        ]);

        Volt::actingAs($user)
            ->test('screen-time.dashboard')
            ->call('extend', 5)
            ->assertStatus(404);

        $this->assertSame(30, $entry->fresh()->minutes);
    }

    public function test_a_running_session_can_be_stopped_early(): void
    {
        Carbon::setTestNow(today()->setTime(15, 20));
        $user = User::factory()->create();
        $entry = ScreenTimeEntry::factory()->for($user)->create([
            'minutes' => 60,
            'started_at' => today()->setTime(15, 0),
        ]);

        $component = Volt::actingAs($user)->test('screen-time.dashboard');
        $component->assertSee('Stop');

        $component->call('stop')->assertHasNoErrors();

        $entry->refresh();

        // Trimmed to the 20 minutes actually used.
        $this->assertSame(20, $entry->minutes);
        $this->assertSame('15:00', $entry->started_at->format('H:i'));
        // Nothing is running any more, so the countdown goes away.
        $this->assertNull($component->instance()->activeEntry);
        $component->assertDontSee('in progress');
    }

    public function test_stopping_reduces_the_daily_total(): void
    {
        Carbon::setTestNow(today()->setTime(15, 20));
        $user = User::factory()->create();
        ScreenTimeEntry::factory()->for($user)->create([
            'minutes' => 60,
            'started_at' => today()->setTime(15, 0),
        ]);

        $component = Volt::actingAs($user)->test('screen-time.dashboard');
        $this->assertSame(60, $component->instance()->today->totalMinutes);

        $component->call('stop');

        $this->assertSame(20, $component->instance()->today->totalMinutes);
    }

    public function test_stopping_part_way_through_a_minute_rounds_down(): void
    {
        Carbon::setTestNow(today()->setTime(15, 20, 40));
        $user = User::factory()->create();
        $entry = ScreenTimeEntry::factory()->for($user)->create([
            'minutes' => 60,
            'started_at' => today()->setTime(15, 0),
        ]);

        $component = Volt::actingAs($user)->test('screen-time.dashboard');
        $component->call('stop');

        $entry->refresh();

        // 20m40s elapsed becomes 20 minutes, never 21 — the session must not
        // still be running once it has been stopped.
        $this->assertSame(20, $entry->minutes);
        $this->assertTrue($entry->endedAt()->lessThanOrEqualTo(now()));
        $this->assertNull($component->instance()->activeEntry);
    }

    public function test_stopping_within_the_first_minute_drops_the_entry(): void
    {
        Carbon::setTestNow(today()->setTime(15, 0, 10));
        $user = User::factory()->create();
        $entry = ScreenTimeEntry::factory()->for($user)->create([
            'minutes' => 60,
            'started_at' => today()->setTime(15, 0),
        ]);

        $component = Volt::actingAs($user)->test('screen-time.dashboard');
        $component->call('stop');

        // No whole minute was used, so there is nothing to record.
        $this->assertDatabaseMissing('screen_time_entries', ['id' => $entry->id]);
        $this->assertNull($component->instance()->activeEntry);
        $this->assertSame(0, $component->instance()->today->totalMinutes);
    }

    public function test_stopping_fails_when_no_session_is_running(): void
    {
        Carbon::setTestNow(today()->setTime(15, 40));
        $user = User::factory()->create();
        $entry = ScreenTimeEntry::factory()->for($user)->create([
            'minutes' => 30,
            'started_at' => today()->setTime(15, 0),
        ]);

        Volt::actingAs($user)
            ->test('screen-time.dashboard')
            ->call('stop')
            ->assertStatus(404);

        $this->assertSame(30, $entry->fresh()->minutes);
    }

    public function test_a_stopped_session_can_still_be_edited_afterwards(): void
    {
        Carbon::setTestNow(today()->setTime(15, 20));
        $user = User::factory()->create();
        $entry = ScreenTimeEntry::factory()->for($user)->create([
            'minutes' => 60,
            'started_at' => today()->setTime(15, 0),
        ]);

        Volt::actingAs($user)->test('screen-time.dashboard')->call('stop');

        Volt::actingAs($user)
            ->test('screen-time.entry-editor')
            ->call('edit', $entry->id)
            ->assertSet('entryMinutes', 20)
            ->set('entryMinutes', 25)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(25, $entry->fresh()->minutes);
    }

    public function test_the_dashboard_picks_up_edits_made_in_the_shared_editor(): void
    {
        Carbon::setTestNow(today()->setTime(18, 0));
        $user = User::factory()->create();
        $entry = ScreenTimeEntry::factory()->for($user)->create([
            'minutes' => 30,
            'started_at' => today()->setTime(9, 0),
        ]);

        $component = Volt::actingAs($user)->test('screen-time.dashboard');
        $this->assertSame(30, $component->instance()->today->totalMinutes);

        // The editor writes directly, then announces itself.
        $entry->update(['minutes' => 90]);
        $component->dispatch('entry-saved');

        $this->assertSame(90, $component->instance()->today->totalMinutes);
    }

    public function test_a_parent_can_remove_an_entry_logged_by_the_other_parent(): void
    {
        $entry = ScreenTimeEntry::factory()->create(['started_at' => today()->setTime(9, 0)]);

        Volt::actingAs(User::factory()->create())
            ->test('screen-time.dashboard')
            ->call('remove', $entry->id);

        $this->assertDatabaseMissing('screen_time_entries', ['id' => $entry->id]);
    }

    public function test_timeline_positions_a_segment_within_the_visible_window(): void
    {
        $day = today();
        $entry = ScreenTimeEntry::factory()->make([
            'type' => ScreenType::Tv,
            'minutes' => 60,
            'started_at' => $day->copy()->setTime(14, 0),
        ]);

        $timeline = DayTimeline::build($day, collect([$entry]));

        // Window is 06:00–22:00 (960 minutes). 14:00 is 480 minutes in.
        $this->assertSame(50.0, $timeline->segments[0]['left']);
        $this->assertSame(6.25, $timeline->segments[0]['width']);
        $this->assertSame(60, $timeline->totalMinutes);
    }

    public function test_timeline_clips_segments_to_the_visible_window(): void
    {
        $day = today();
        $entry = ScreenTimeEntry::factory()->make([
            'minutes' => 120,
            'started_at' => $day->copy()->setTime(5, 0),
        ]);

        $timeline = DayTimeline::build($day, collect([$entry]));

        // Only 06:00–07:00 is visible: 60 of 960 minutes, starting at the left edge.
        $this->assertSame(0.0, $timeline->segments[0]['left']);
        $this->assertSame(6.25, $timeline->segments[0]['width']);
        // The whole session still counts toward the daily total.
        $this->assertSame(120, $timeline->totalMinutes);
    }

    public function test_timeline_drops_segments_entirely_outside_the_window(): void
    {
        $day = today();
        $entry = ScreenTimeEntry::factory()->make([
            'minutes' => 30,
            'started_at' => $day->copy()->setTime(23, 0),
        ]);

        $timeline = DayTimeline::build($day, collect([$entry]));

        $this->assertSame([], $timeline->segments);
        $this->assertSame(30, $timeline->totalMinutes);
    }

    public function test_over_limit_is_flagged_against_the_days_own_allowance(): void
    {
        $under = DayTimeline::build(today(), collect([
            ScreenTimeEntry::factory()->make(['minutes' => 60, 'started_at' => today()->setTime(9, 0)]),
        ]), limitMinutes: 60);
        $over = DayTimeline::build(today(), collect([
            ScreenTimeEntry::factory()->make(['minutes' => 90, 'started_at' => today()->setTime(9, 0)]),
        ]), limitMinutes: 60);

        $this->assertFalse($under->isOverLimit());
        $this->assertTrue($over->isOverLimit());
        $this->assertSame(0, $under->remainingMinutes());
        $this->assertTrue($over->limitIsOverridden);
    }

    /**
     * 3h every day in July and August; during the school year 3h at weekends
     * and 2h30 on weekdays.
     */
    #[DataProvider('scheduledLimits')]
    public function test_scheduled_allowance_follows_the_calendar(string $date, int $expected, string $reason): void
    {
        $day = Carbon::parse($date);

        $this->assertSame($expected, ScreenTimeLimit::default($day));
        $this->assertSame($reason, ScreenTimeLimit::reason($day));
    }

    public static function scheduledLimits(): array
    {
        return [
            'july weekday' => ['2026-07-15', 180, 'Summer holidays'],
            'july weekend' => ['2026-07-18', 180, 'Summer holidays'],
            'august weekday' => ['2026-08-05', 180, 'Summer holidays'],
            'september weekday' => ['2026-09-02', 150, 'School-year weekday'],
            'september saturday' => ['2026-09-05', 180, 'Weekend'],
            'september sunday' => ['2026-09-06', 180, 'Weekend'],
            'january weekday' => ['2026-01-07', 150, 'School-year weekday'],
            'june weekday' => ['2026-06-10', 150, 'School-year weekday'],
            'june weekend' => ['2026-06-13', 180, 'Weekend'],
        ];
    }

    public function test_an_override_replaces_the_scheduled_allowance(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00')); // a school-year weekday
        $user = User::factory()->create();

        $this->assertSame(150, ScreenTimeLimit::for(today()));

        Volt::actingAs($user)
            ->test('screen-time.dashboard')
            ->call('editLimit', today()->toDateString())
            ->assertSet('limitMinutes', 150)
            ->set('limitMinutes', 45)
            ->call('saveLimit')
            ->assertHasNoErrors()
            ->assertSet('editingLimit', false);

        $this->assertSame(45, ScreenTimeLimit::for(today()));
    }

    public function test_an_override_can_be_reset_to_the_default(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00'));
        $user = User::factory()->create();
        ScreenTimeLimitOverride::factory()->create(['date' => today(), 'minutes' => 45]);

        Volt::actingAs($user)
            ->test('screen-time.dashboard')
            ->call('editLimit', today()->toDateString())
            ->call('resetLimit');

        $this->assertSame(0, ScreenTimeLimitOverride::count());
        $this->assertSame(150, ScreenTimeLimit::for(today()));
    }

    public function test_overriding_the_same_day_twice_updates_in_place(): void
    {
        $user = User::factory()->create();

        $component = Volt::actingAs($user)->test('screen-time.dashboard');

        $component->call('editLimit', today()->toDateString())->set('limitMinutes', 60)->call('saveLimit');
        $component->call('editLimit', today()->toDateString())->set('limitMinutes', 90)->call('saveLimit');

        $this->assertSame(1, ScreenTimeLimitOverride::count());
        $this->assertSame(90, ScreenTimeLimit::for(today()));
    }

    public function test_an_override_for_today_is_picked_up_by_the_dashboard(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00'));
        $user = User::factory()->create();

        $component = Volt::actingAs($user)->test('screen-time.dashboard');
        $component->call('editLimit', today()->toDateString())->set('limitMinutes', 45)->call('saveLimit');

        $this->assertSame(45, $component->instance()->today->limitMinutes);
        $this->assertTrue($component->instance()->today->limitIsOverridden);
    }

    public function test_an_override_is_rejected_for_a_day_that_is_not_on_screen(): void
    {
        $user = User::factory()->create();

        Volt::actingAs($user)
            ->test('screen-time.dashboard')
            ->call('editLimit', today()->subYear()->toDateString())
            ->assertStatus(404);

        $this->assertSame(0, ScreenTimeLimitOverride::count());
    }

    public function test_an_override_must_be_a_sane_number_of_minutes(): void
    {
        $user = User::factory()->create();

        Volt::actingAs($user)
            ->test('screen-time.dashboard')
            ->call('editLimit', today()->toDateString())
            ->set('limitMinutes', -5)
            ->call('saveLimit')
            ->assertHasErrors('limitMinutes');

        $this->assertSame(0, ScreenTimeLimitOverride::count());
    }

    public function test_each_day_carries_its_own_allowance(): void
    {
        // Saturday 2026-09-05, so the visible days straddle a weekend.
        Carbon::setTestNow(Carbon::parse('2026-09-05 12:00'));
        $user = User::factory()->create();
        ScreenTimeLimitOverride::factory()->create([
            'date' => today()->subDays(2), // Thursday
            'minutes' => 30,
        ]);

        $timelines = Volt::actingAs($user)->test('screen-time.dashboard')->instance()->timelines;

        $limits = collect($timelines)->mapWithKeys(
            fn (DayTimeline $timeline) => [$timeline->day->format('D') => $timeline->limitMinutes],
        );

        $this->assertSame(180, $limits['Sat']);   // weekend
        $this->assertSame(150, $limits['Fri']);   // school-year weekday
        $this->assertSame(30, $limits['Thu']);    // manual override
        $this->assertTrue(collect($timelines)->firstWhere(
            fn (DayTimeline $t) => $t->day->format('D') === 'Thu'
        )->limitIsOverridden);
    }

    public function test_the_dashboard_totals_every_users_entries_together(): void
    {
        Carbon::setTestNow(today()->setTime(12, 0));
        $user = User::factory()->create();

        // One entry from each parent — the dashboard tracks a single kid, so
        // both count toward the same day.
        ScreenTimeEntry::factory()->for($user)->create(['minutes' => 30, 'started_at' => today()->setTime(9, 0)]);
        ScreenTimeEntry::factory()->create(['minutes' => 60, 'started_at' => today()->setTime(10, 0)]);

        $component = Volt::actingAs($user)->test('screen-time.dashboard');

        $this->assertSame(90, $component->instance()->today->totalMinutes);
    }
}
