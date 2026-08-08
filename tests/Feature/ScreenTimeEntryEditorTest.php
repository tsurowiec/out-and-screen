<?php

namespace Tests\Feature;

use App\Enums\ScreenType;
use App\Models\ScreenTimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The entry editor is shared by the dashboard and the full entry list, so it is
 * exercised on its own here.
 */
class ScreenTimeEntryEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_entry_type_can_be_changed(): void
    {
        $user = User::factory()->create();
        $entry = ScreenTimeEntry::factory()->for($user)->create([
            'type' => ScreenType::Tv,
            'minutes' => 30,
            'started_at' => today()->setTime(9, 0),
        ]);

        Volt::actingAs($user)
            ->test('screen-time.entry-editor')
            ->call('edit', $entry->id)
            ->assertSet('entryType', 'tv')
            ->set('entryType', 'playstation')
            ->call('save')
            ->assertHasNoErrors();

        $entry->refresh();

        $this->assertSame(ScreenType::Playstation, $entry->type);
        // Time and duration are untouched when only the type changes.
        $this->assertSame('09:00', $entry->started_at->format('H:i'));
        $this->assertSame(30, $entry->minutes);
    }

    public function test_an_entry_type_must_be_a_known_screen_type(): void
    {
        $user = User::factory()->create();
        $entry = ScreenTimeEntry::factory()->for($user)->create([
            'type' => ScreenType::Tv,
            'minutes' => 30,
            'started_at' => today()->setTime(9, 0),
        ]);

        Volt::actingAs($user)
            ->test('screen-time.entry-editor')
            ->call('edit', $entry->id)
            ->set('entryType', 'nintendo')
            ->call('save')
            ->assertHasErrors('entryType');

        $this->assertSame(ScreenType::Tv, $entry->fresh()->type);
    }

    public function test_an_entry_start_time_and_duration_can_be_edited(): void
    {
        Carbon::setTestNow(today()->setTime(18, 0));
        $user = User::factory()->create();
        $entry = ScreenTimeEntry::factory()->for($user)->create([
            'type' => ScreenType::Tv,
            'minutes' => 30,
            'started_at' => today()->setTime(9, 0),
        ]);

        Volt::actingAs($user)
            ->test('screen-time.entry-editor')
            ->call('edit', $entry->id)
            ->assertSet('entryTime', '09:00')
            ->assertSet('entryMinutes', 30)
            ->set('entryTime', '14:45')
            ->set('entryMinutes', 75)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('open', false)
            ->assertDispatched('entry-saved');

        $entry->refresh();

        $this->assertSame('14:45', $entry->started_at->format('H:i'));
        $this->assertSame(75, $entry->minutes);
        // Editing the time of day must not move the entry to another date.
        $this->assertTrue($entry->started_at->isSameDay(today()));
        // The type is left alone when it isn't touched.
        $this->assertSame(ScreenType::Tv, $entry->type);
    }

    public function test_an_entry_from_an_earlier_day_keeps_its_date(): void
    {
        $user = User::factory()->create();
        $entry = ScreenTimeEntry::factory()->for($user)->create([
            'minutes' => 30,
            'started_at' => today()->subDays(20)->setTime(9, 0),
        ]);

        Volt::actingAs($user)
            ->test('screen-time.entry-editor')
            ->call('edit', $entry->id)
            ->set('entryTime', '20:15')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            today()->subDays(20)->setTime(20, 15)->toDateTimeString(),
            $entry->fresh()->started_at->toDateTimeString(),
        );
    }

    public function test_an_edited_entry_is_rejected_when_invalid(): void
    {
        $user = User::factory()->create();
        $entry = ScreenTimeEntry::factory()->for($user)->create([
            'minutes' => 30,
            'started_at' => today()->setTime(9, 0),
        ]);

        Volt::actingAs($user)
            ->test('screen-time.entry-editor')
            ->call('edit', $entry->id)
            ->set('entryTime', 'half past nine')
            ->set('entryMinutes', 0)
            ->call('save')
            ->assertHasErrors(['entryTime', 'entryMinutes']);

        $this->assertSame(30, $entry->fresh()->minutes);
    }

    public function test_a_parent_can_edit_an_entry_logged_by_the_other_parent(): void
    {
        $entry = ScreenTimeEntry::factory()->create([
            'minutes' => 30,
            'started_at' => today()->setTime(9, 0),
        ]);

        Volt::actingAs(User::factory()->create())
            ->test('screen-time.entry-editor')
            ->call('edit', $entry->id)
            ->set('entryMinutes', 45)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(45, $entry->fresh()->minutes);
    }

    public function test_the_child_cannot_open_or_save_the_editor(): void
    {
        $entry = ScreenTimeEntry::factory()->create([
            'minutes' => 30,
            'started_at' => today()->setTime(9, 0),
        ]);
        $child = User::factory()->child()->create();

        Volt::actingAs($child)
            ->test('screen-time.entry-editor')
            ->call('edit', $entry->id)
            ->assertForbidden();

        Volt::actingAs($child)
            ->test('screen-time.entry-editor')
            ->set('entryId', $entry->id)
            ->set('entryType', 'tv')
            ->set('entryTime', '10:00')
            ->set('entryMinutes', 90)
            ->call('save')
            ->assertForbidden();

        $this->assertSame(30, $entry->fresh()->minutes);
    }

    public function test_an_unknown_entry_is_a_404(): void
    {
        Volt::actingAs(User::factory()->create())
            ->test('screen-time.entry-editor')
            ->call('edit', 12345)
            ->assertStatus(404);
    }
}
