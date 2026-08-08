<?php

namespace Tests\Feature;

use App\Enums\ScreenType;
use App\Models\ScreenTimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ScreenTimeEntriesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_requires_authentication(): void
    {
        $this->get('/screen-time')->assertRedirect('/login');
    }

    public function test_the_page_lists_entries_newest_first(): void
    {
        $user = User::factory()->create();
        $older = ScreenTimeEntry::factory()->for($user)->create(['started_at' => today()->subDays(3)->setTime(9, 0)]);
        $newer = ScreenTimeEntry::factory()->for($user)->create(['started_at' => today()->setTime(9, 0)]);

        $entries = Volt::actingAs($user)->test('screen-time.entries')->viewData('entries');

        $this->assertSame([$newer->id, $older->id], $entries->pluck('id')->all());
    }

    public function test_the_page_reaches_back_beyond_the_dashboard_window(): void
    {
        $user = User::factory()->create();
        ScreenTimeEntry::factory()->for($user)->create(['started_at' => today()->subDays(90)->setTime(9, 0)]);

        $this->assertSame(
            1,
            Volt::actingAs($user)->test('screen-time.entries')->viewData('entries')->total(),
        );
    }

    public function test_entries_are_paginated(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 30) as $day) {
            ScreenTimeEntry::factory()->for($user)->create(['started_at' => today()->subDays($day)->setTime(9, 0)]);
        }

        $component = Volt::actingAs($user)->test('screen-time.entries');

        $this->assertCount(25, $component->viewData('entries')->items());
        $this->assertSame(30, $component->viewData('entries')->total());

        $component->set('paginators.page', 2);
        $this->assertCount(5, $component->viewData('entries')->items());
    }

    public function test_entries_can_be_filtered_by_type(): void
    {
        $user = User::factory()->create();
        ScreenTimeEntry::factory()->for($user)->create(['type' => ScreenType::Tv, 'started_at' => today()->setTime(9, 0)]);
        ScreenTimeEntry::factory()->for($user)->create(['type' => ScreenType::Youtube, 'started_at' => today()->setTime(11, 0)]);

        $component = Volt::actingAs($user)->test('screen-time.entries')->set('filter', 'youtube');

        $entries = $component->viewData('entries');

        $this->assertSame(1, $entries->total());
        $this->assertSame(ScreenType::Youtube, $entries->first()->type);
    }

    public function test_filtering_returns_to_the_first_page(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 30) as $day) {
            ScreenTimeEntry::factory()->for($user)->create([
                'type' => ScreenType::Tv,
                'started_at' => today()->subDays($day)->setTime(9, 0),
            ]);
        }

        Volt::actingAs($user)
            ->test('screen-time.entries')
            ->set('paginators.page', 2)
            ->set('filter', 'tv')
            ->assertSet('paginators.page', 1);
    }

    public function test_an_entry_can_be_deleted_from_the_page(): void
    {
        $user = User::factory()->create();
        $entry = ScreenTimeEntry::factory()->for($user)->create(['started_at' => today()->subDays(10)->setTime(9, 0)]);

        Volt::actingAs($user)->test('screen-time.entries')->call('remove', $entry->id);

        $this->assertDatabaseMissing('screen_time_entries', ['id' => $entry->id]);
    }

    public function test_a_parent_can_delete_an_entry_logged_by_the_other_parent(): void
    {
        $entry = ScreenTimeEntry::factory()->create(['started_at' => today()->setTime(9, 0)]);

        Volt::actingAs(User::factory()->create())->test('screen-time.entries')->call('remove', $entry->id);

        $this->assertDatabaseMissing('screen_time_entries', ['id' => $entry->id]);
    }

    public function test_the_page_lists_every_users_entries(): void
    {
        $user = User::factory()->create();
        ScreenTimeEntry::factory()->for($user)->create(['started_at' => today()->setTime(9, 0)]);
        ScreenTimeEntry::factory()->create(['started_at' => today()->setTime(11, 0)]);

        $this->assertSame(
            2,
            Volt::actingAs($user)->test('screen-time.entries')->viewData('entries')->total(),
        );
    }

    public function test_the_child_sees_the_list_but_cannot_delete_from_it(): void
    {
        $entry = ScreenTimeEntry::factory()->create(['started_at' => today()->setTime(9, 0)]);
        $child = User::factory()->child()->create();

        $component = Volt::actingAs($child)->test('screen-time.entries');

        $this->assertSame(1, $component->viewData('entries')->total());

        $component->call('remove', $entry->id)->assertForbidden();

        $this->assertDatabaseHas('screen_time_entries', ['id' => $entry->id]);
    }

    public function test_the_page_renders_with_a_link_in_the_sidebar(): void
    {
        $user = User::factory()->create();
        ScreenTimeEntry::factory()->for($user)->create(['started_at' => today()->subDays(40)->setTime(9, 0)]);

        $this->actingAs($user)
            ->get('/screen-time')
            ->assertOk()
            ->assertSee('Screen time')
            ->assertSee(route('screen-time'), false);
    }
}
