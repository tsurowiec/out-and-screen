<?php

namespace Tests\Feature;

use App\Models\TripEntry;
use App\Models\User;
use App\Support\TripWeek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

class TripsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday, so "this week" started on the Saturday three days back.
        Carbon::setTestNow(Carbon::parse('2026-08-05 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_page_requires_authentication(): void
    {
        $this->get('/trips')->assertRedirect('/login');
    }

    public function test_the_page_renders_with_a_link_in_the_sidebar(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/trips')
            ->assertOk()
            ->assertSee('Trips')
            ->assertSee(route('trips'), false);
    }

    public function test_the_week_runs_from_saturday_to_friday(): void
    {
        $this->assertSame('2026-08-01', TripWeek::startFor(Carbon::parse('2026-08-05'))->toDateString());
        $this->assertSame('2026-08-07', TripWeek::endFor(Carbon::parse('2026-08-05'))->toDateString());

        // The Saturday itself opens a new week rather than closing the old one.
        $this->assertSame('2026-08-08', TripWeek::startFor(Carbon::parse('2026-08-08'))->toDateString());

        // The Friday before belongs to the previous week.
        $this->assertSame('2026-07-25', TripWeek::startFor(Carbon::parse('2026-07-31'))->toDateString());
    }

    public function test_a_trip_is_logged_against_today_by_default(): void
    {
        $user = User::factory()->create();

        Volt::actingAs($user)
            ->test('trips.index')
            ->assertSet('date', '2026-08-05')
            ->set('hours', '2.5')
            ->set('description', 'Lake')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('trip_entries', [
            'date' => '2026-08-05',
            'minutes' => 150,
            'description' => 'Lake',
            'user_id' => $user->id,
        ]);
    }

    public function test_the_description_is_optional(): void
    {
        Volt::actingAs(User::factory()->create())
            ->test('trips.index')
            ->set('hours', '1')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('trip_entries', ['minutes' => 60, 'description' => null]);
    }

    public function test_a_duration_is_required(): void
    {
        Volt::actingAs(User::factory()->create())
            ->test('trips.index')
            ->call('save')
            ->assertHasErrors(['hours' => 'required']);

        $this->assertDatabaseCount('trip_entries', 0);
    }

    public function test_the_form_resets_after_a_trip_is_logged(): void
    {
        Volt::actingAs(User::factory()->create())
            ->test('trips.index')
            ->set('date', '2026-08-01')
            ->set('hours', '3')
            ->set('description', 'Zoo')
            ->call('save')
            ->assertSet('date', '2026-08-05')
            ->assertSet('hours', '')
            ->assertSet('description', '')
            ->assertSet('editing', false);
    }

    public function test_weekly_totals_group_saturday_through_friday(): void
    {
        $user = User::factory()->create();

        // This week: Saturday 1 Aug and Wednesday 5 Aug.
        TripEntry::factory()->for($user)->create(['date' => '2026-08-01', 'minutes' => 120]);
        TripEntry::factory()->for($user)->create(['date' => '2026-08-05', 'minutes' => 90]);
        // Previous week: Friday 31 Jul.
        TripEntry::factory()->for($user)->create(['date' => '2026-07-31', 'minutes' => 60]);

        $weeks = Volt::actingAs($user)->test('trips.index')->viewData('weeks');

        $this->assertSame('2026-08-01', $weeks[0]['start']->toDateString());
        $this->assertTrue($weeks[0]['current']);
        $this->assertSame(210, $weeks[0]['minutes']);

        $this->assertSame('2026-07-25', $weeks[1]['start']->toDateString());
        $this->assertSame(60, $weeks[1]['minutes']);
    }

    public function test_the_current_week_is_shown_even_when_empty(): void
    {
        $user = User::factory()->create();
        TripEntry::factory()->for($user)->create(['date' => '2026-07-31', 'minutes' => 60]);

        $weeks = Volt::actingAs($user)->test('trips.index')->viewData('weeks');

        $this->assertSame('2026-08-01', $weeks[0]['start']->toDateString());
        $this->assertSame(0, $weeks[0]['minutes']);
        $this->assertCount(2, $weeks->items());
    }

    public function test_weeks_are_paginated_so_totals_are_never_split(): void
    {
        $user = User::factory()->create();

        // One trip a week, ten weeks back.
        foreach (range(1, 10) as $week) {
            TripEntry::factory()->for($user)->create([
                'date' => Carbon::parse('2026-08-01')->subWeeks($week)->toDateString(),
                'minutes' => 60,
            ]);
        }

        $component = Volt::actingAs($user)->test('trips.index');

        $this->assertCount(8, $component->viewData('weeks')->items());
        $this->assertSame(11, $component->viewData('weeks')->total());

        $component->set('paginators.page', 2);
        $this->assertCount(3, $component->viewData('weeks')->items());
    }

    public function test_a_trip_can_be_edited(): void
    {
        $user = User::factory()->create();
        $trip = TripEntry::factory()->for($user)->create([
            'date' => '2026-08-05',
            'minutes' => 90,
            'description' => 'Park',
        ]);

        Volt::actingAs($user)
            ->test('trips.index')
            ->call('edit', $trip->id)
            ->assertSet('editing', true)
            ->assertSet('date', '2026-08-05')
            ->assertSet('hours', '1.5')
            ->assertSet('description', 'Park')
            ->set('date', '2026-08-02')
            ->set('hours', '4')
            ->set('description', 'Mountains')
            ->call('save')
            ->assertHasNoErrors();

        $trip->refresh();

        $this->assertSame('2026-08-02', $trip->date->toDateString());
        $this->assertSame(240, $trip->minutes);
        $this->assertSame('Mountains', $trip->description);
    }

    public function test_a_trip_can_be_removed(): void
    {
        $user = User::factory()->create();
        $trip = TripEntry::factory()->for($user)->create(['date' => '2026-08-05']);

        Volt::actingAs($user)->test('trips.index')->call('remove', $trip->id);

        $this->assertDatabaseMissing('trip_entries', ['id' => $trip->id]);
    }

    public function test_the_page_lists_every_users_trips(): void
    {
        $user = User::factory()->create();
        TripEntry::factory()->for($user)->create(['date' => '2026-08-05', 'minutes' => 60]);
        TripEntry::factory()->create(['date' => '2026-08-05', 'minutes' => 30]);

        $weeks = Volt::actingAs($user)->test('trips.index')->viewData('weeks');

        $this->assertSame(90, $weeks[0]['minutes']);
        $this->assertCount(2, $weeks[0]['entries']);
    }

    public function test_the_dashboard_totals_this_weeks_trips(): void
    {
        $user = User::factory()->create();
        TripEntry::factory()->for($user)->create(['date' => '2026-08-01', 'minutes' => 240]);
        TripEntry::factory()->for($user)->create(['date' => '2026-08-05', 'minutes' => 120]);
        // Last week, so outside the total.
        TripEntry::factory()->for($user)->create(['date' => '2026-07-31', 'minutes' => 60]);

        $this->assertSame(
            360,
            Volt::actingAs($user)->test('screen-time.dashboard')->get('tripMinutesThisWeek'),
        );

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Trips this week')
            ->assertSee('6h');
    }

    public function test_the_child_sees_trips_but_cannot_change_them(): void
    {
        $trip = TripEntry::factory()->create(['date' => '2026-08-05', 'minutes' => 60]);
        $child = User::factory()->child()->create();

        $component = Volt::actingAs($child)->test('trips.index');

        $this->assertSame(60, $component->viewData('weeks')[0]['minutes']);

        $component->call('create')->assertForbidden();

        Volt::actingAs($child)->test('trips.index')->call('edit', $trip->id)->assertForbidden();
        Volt::actingAs($child)->test('trips.index')->call('remove', $trip->id)->assertForbidden();
        Volt::actingAs($child)->test('trips.index')->set('hours', '2')->call('save')->assertForbidden();

        $this->assertDatabaseCount('trip_entries', 1);
    }
}
