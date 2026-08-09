<?php

namespace Tests\Feature;

use App\Enums\ScreenType;
use App\Models\ScreenTimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The splitter is shared by the dashboard and the full entry list, so it is
 * exercised on its own here.
 */
class ScreenTimeEntrySplitterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_opens_split_down_the_middle(): void
    {
        $user = User::factory()->create();
        $entry = ScreenTimeEntry::factory()->for($user)->create([
            'type' => ScreenType::Mobile,
            'minutes' => 60,
            'started_at' => today()->setTime(9, 0),
        ]);

        Volt::actingAs($user)
            ->test('screen-time.entry-splitter')
            ->call('openFor', $entry->id)
            ->assertSet('open', true)
            ->assertSet('totalMinutes', 60)
            ->assertSet('firstMinutes', 30)
            ->assertSet('firstType', 'mobile')
            ->assertSet('secondType', 'mobile');
    }

    public function test_the_opening_split_is_snapped_onto_the_five_minute_steps(): void
    {
        $user = User::factory()->create();

        // 22.5 minutes isn't a stop on the slider, so it opens at 25.
        $odd = ScreenTimeEntry::factory()->for($user)->create([
            'minutes' => 45,
            'started_at' => today()->setTime(9, 0),
        ]);

        // Half of 10 is a stop, but half of 11 would round up past the last one.
        $short = ScreenTimeEntry::factory()->for($user)->create([
            'minutes' => 11,
            'started_at' => today()->setTime(12, 0),
        ]);

        Volt::actingAs($user)
            ->test('screen-time.entry-splitter')
            ->call('openFor', $odd->id)
            ->assertSet('firstMinutes', 25)
            ->call('openFor', $short->id)
            ->assertSet('firstMinutes', 5);
    }

    public function test_an_entry_is_split_into_two_back_to_back_entries(): void
    {
        $user = User::factory()->create();
        $entry = ScreenTimeEntry::factory()->for($user)->create([
            'type' => ScreenType::Mobile,
            'minutes' => 60,
            'started_at' => today()->setTime(9, 0),
        ]);

        Volt::actingAs($user)
            ->test('screen-time.entry-splitter')
            ->call('openFor', $entry->id)
            ->set('firstMinutes', 20)
            ->set('secondType', 'youtube')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('open', false)
            ->assertDispatched('entry-saved');

        $entry->refresh();

        $this->assertSame(ScreenType::Mobile, $entry->type);
        $this->assertSame(20, $entry->minutes);
        $this->assertSame('09:00', $entry->started_at->format('H:i'));

        $second = ScreenTimeEntry::query()->whereKeyNot($entry->id)->sole();

        $this->assertSame(ScreenType::Youtube, $second->type);
        $this->assertSame(40, $second->minutes);
        // The second half picks up exactly where the first one ends.
        $this->assertSame('09:20', $second->started_at->format('H:i'));
        // The day's total is unchanged by a split.
        $this->assertSame($user->id, $second->user_id);
        $this->assertSame(60, (int) ScreenTimeEntry::query()->sum('minutes'));
    }

    public function test_both_halves_can_be_given_a_new_type(): void
    {
        $user = User::factory()->create();
        $entry = ScreenTimeEntry::factory()->for($user)->create([
            'type' => ScreenType::Mobile,
            'minutes' => 45,
            'started_at' => today()->setTime(16, 30),
        ]);

        Volt::actingAs($user)
            ->test('screen-time.entry-splitter')
            ->call('openFor', $entry->id)
            ->set('firstType', 'tv')
            ->set('secondType', 'playstation')
            ->set('firstMinutes', 15)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(ScreenType::Tv, $entry->fresh()->type);
        $this->assertSame(15, $entry->fresh()->minutes);

        $second = ScreenTimeEntry::query()->whereKeyNot($entry->id)->sole();

        $this->assertSame(ScreenType::Playstation, $second->type);
        $this->assertSame(30, $second->minutes);
    }

    public function test_the_split_must_leave_minutes_on_both_sides(): void
    {
        $user = User::factory()->create();
        $entry = ScreenTimeEntry::factory()->for($user)->create([
            'minutes' => 30,
            'started_at' => today()->setTime(9, 0),
        ]);

        $component = Volt::actingAs($user)
            ->test('screen-time.entry-splitter')
            ->call('openFor', $entry->id);

        $component->set('firstMinutes', 0)->call('save')->assertHasErrors('firstMinutes');
        $component->set('firstMinutes', 30)->call('save')->assertHasErrors('firstMinutes');

        $this->assertSame(1, ScreenTimeEntry::query()->count());
        $this->assertSame(30, $entry->fresh()->minutes);
    }

    public function test_an_entry_shorter_than_two_steps_cannot_be_split(): void
    {
        $user = User::factory()->create();
        $entry = ScreenTimeEntry::factory()->for($user)->create([
            'minutes' => 9,
            'started_at' => today()->setTime(9, 0),
        ]);

        Volt::actingAs($user)
            ->test('screen-time.entry-splitter')
            ->call('openFor', $entry->id)
            ->assertStatus(422);
    }

    public function test_the_child_cannot_open_or_save_a_split(): void
    {
        $entry = ScreenTimeEntry::factory()->create([
            'type' => ScreenType::Mobile,
            'minutes' => 60,
            'started_at' => today()->setTime(9, 0),
        ]);
        $child = User::factory()->child()->create();

        Volt::actingAs($child)
            ->test('screen-time.entry-splitter')
            ->call('openFor', $entry->id)
            ->assertForbidden();

        Volt::actingAs($child)
            ->test('screen-time.entry-splitter')
            ->set('entryId', $entry->id)
            ->set('firstType', 'mobile')
            ->set('secondType', 'youtube')
            ->set('firstMinutes', 20)
            ->call('save')
            ->assertForbidden();

        $this->assertSame(1, ScreenTimeEntry::query()->count());
        $this->assertSame(60, $entry->fresh()->minutes);
    }

    public function test_an_unknown_entry_is_a_404(): void
    {
        Volt::actingAs(User::factory()->create())
            ->test('screen-time.entry-splitter')
            ->call('openFor', 12345)
            ->assertStatus(404);
    }
}
