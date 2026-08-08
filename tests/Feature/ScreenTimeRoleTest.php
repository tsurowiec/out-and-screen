<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ScreenTimeEntry;
use App\Models\ScreenTimeLimitOverride;
use App\Models\User;
use App\Support\ScreenTimeLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * One app, one kid: everyone who logs in shares the same data. Parents may
 * change it, the kid may only look at it.
 */
class ScreenTimeRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_are_parents_by_default(): void
    {
        $this->assertSame(UserRole::Parent, User::factory()->create()->role);
        $this->assertTrue(User::factory()->create()->canManageScreenTime());
        $this->assertFalse(User::factory()->child()->create()->canManageScreenTime());
    }

    public function test_the_child_sees_the_same_dashboard_data_as_a_parent(): void
    {
        Carbon::setTestNow(today()->setTime(18, 0));
        ScreenTimeEntry::factory()->create(['minutes' => 45, 'started_at' => today()->setTime(9, 0)]);

        $parentTotal = Volt::actingAs(User::factory()->create())
            ->test('screen-time.dashboard')->instance()->today->totalMinutes;

        $childTotal = Volt::actingAs(User::factory()->child()->create())
            ->test('screen-time.dashboard')->instance()->today->totalMinutes;

        $this->assertSame(45, $parentTotal);
        $this->assertSame($parentTotal, $childTotal);
    }

    public function test_the_child_is_not_shown_the_controls(): void
    {
        Carbon::setTestNow(today()->setTime(15, 10));
        ScreenTimeEntry::factory()->create(['minutes' => 60, 'started_at' => today()->setTime(15, 0)]);

        $response = $this->actingAs(User::factory()->child()->create())->get('/dashboard')->assertOk();

        $response->assertDontSee('Add screen time');
        $response->assertDontSee('Extend by');
        $response->assertDontSee('Allowance');
        // The read-only view still shows what's going on.
        $response->assertSee('in progress');
        $response->assertSee('Last 7 days');
    }

    public function test_a_parent_is_shown_the_controls(): void
    {
        Carbon::setTestNow(today()->setTime(15, 10));
        ScreenTimeEntry::factory()->create(['minutes' => 60, 'started_at' => today()->setTime(15, 0)]);

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Add screen time')
            ->assertSee('Extend by')
            ->assertSee('Allowance');
    }

    public function test_the_child_cannot_log_screen_time(): void
    {
        Volt::actingAs(User::factory()->child()->create())
            ->test('screen-time.dashboard')
            ->call('add', 30)
            ->assertForbidden();

        $this->assertSame(0, ScreenTimeEntry::count());
    }

    public function test_the_child_cannot_extend_or_stop_a_session(): void
    {
        Carbon::setTestNow(today()->setTime(15, 10));
        $entry = ScreenTimeEntry::factory()->create(['minutes' => 60, 'started_at' => today()->setTime(15, 0)]);
        $child = User::factory()->child()->create();

        Volt::actingAs($child)->test('screen-time.dashboard')->call('extend', 15)->assertForbidden();
        Volt::actingAs($child)->test('screen-time.dashboard')->call('stop')->assertForbidden();

        $this->assertSame(60, $entry->fresh()->minutes);
    }

    public function test_the_child_cannot_delete_an_entry(): void
    {
        $entry = ScreenTimeEntry::factory()->create(['started_at' => today()->setTime(9, 0)]);

        Volt::actingAs(User::factory()->child()->create())
            ->test('screen-time.dashboard')
            ->call('remove', $entry->id)
            ->assertForbidden();

        $this->assertDatabaseHas('screen_time_entries', ['id' => $entry->id]);
    }

    public function test_the_child_cannot_change_the_allowance(): void
    {
        $child = User::factory()->child()->create();

        Volt::actingAs($child)
            ->test('screen-time.dashboard')
            ->call('editLimit', today()->toDateString())
            ->assertForbidden();

        Volt::actingAs($child)
            ->test('screen-time.dashboard')
            ->set('editingDate', today()->toDateString())
            ->set('limitMinutes', 600)
            ->call('saveLimit')
            ->assertForbidden();

        $this->assertSame(0, ScreenTimeLimitOverride::count());
    }

    public function test_an_allowance_override_applies_to_everyone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00')); // school-year weekday
        $parent = User::factory()->create();

        Volt::actingAs($parent)
            ->test('screen-time.dashboard')
            ->call('editLimit', today()->toDateString())
            ->set('limitMinutes', 45)
            ->call('saveLimit');

        $this->assertSame(45, ScreenTimeLimit::for(today()));

        // The other parent and the kid see the same allowance.
        foreach ([User::factory()->create(), User::factory()->child()->create()] as $user) {
            $this->assertSame(
                45,
                Volt::actingAs($user)->test('screen-time.dashboard')->instance()->today->limitMinutes,
            );
        }
    }

    public function test_entries_survive_the_account_that_logged_them(): void
    {
        $parent = User::factory()->create();
        $entry = ScreenTimeEntry::factory()->for($parent)->create(['started_at' => today()->setTime(9, 0)]);

        $parent->delete();

        // Shared data must not disappear with a parent's account.
        $this->assertDatabaseHas('screen_time_entries', ['id' => $entry->id]);
        $this->assertNull($entry->fresh()->user_id);
    }

    public function test_the_role_command_switches_a_user_over(): void
    {
        $user = User::factory()->create();

        $this->artisan("screen-time:role {$user->email} child")->assertSuccessful();
        $this->assertFalse($user->fresh()->canManageScreenTime());

        $this->artisan("screen-time:role {$user->email} parent")->assertSuccessful();
        $this->assertTrue($user->fresh()->canManageScreenTime());

        $this->artisan("screen-time:role {$user->email} wizard")->assertFailed();
        $this->artisan('screen-time:role nobody@example.com child')->assertFailed();
    }
}
