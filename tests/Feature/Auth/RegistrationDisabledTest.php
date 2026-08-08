<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * This is a private app for one household, so accounts are created from the
 * console rather than by anyone who finds the URL.
 */
class RegistrationDisabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_registration_page_is_gone(): void
    {
        $this->get('/register')->assertNotFound();
        $this->assertFalse(app('router')->has('register'));
    }

    public function test_the_login_page_does_not_offer_sign_up(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('Sign up');
    }

    public function test_an_account_can_be_created_from_the_console(): void
    {
        $this->artisan('screen-time:user', [
            'name' => 'A Parent',
            'email' => 'parent@example.com',
            '--password' => 'a-good-password',
        ])->assertSuccessful();

        $user = User::sole();

        $this->assertSame('parent@example.com', $user->email);
        $this->assertSame(UserRole::Parent, $user->role);
        $this->assertTrue(Hash::check('a-good-password', $user->password));
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_a_read_only_account_can_be_created(): void
    {
        $this->artisan('screen-time:user', [
            'name' => 'The Kid',
            'email' => 'kid@example.com',
            '--role' => 'child',
            '--password' => 'a-good-password',
        ])->assertSuccessful();

        $this->assertFalse(User::sole()->canManageScreenTime());
    }

    public function test_the_console_rejects_bad_input(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        // Duplicate email.
        $this->artisan('screen-time:user', [
            'name' => 'Someone',
            'email' => 'taken@example.com',
            '--password' => 'a-good-password',
        ])->assertFailed();

        // Password too short.
        $this->artisan('screen-time:user', [
            'name' => 'Someone',
            'email' => 'new@example.com',
            '--password' => 'short',
        ])->assertFailed();

        // Unknown role.
        $this->artisan('screen-time:user', [
            'name' => 'Someone',
            'email' => 'other@example.com',
            '--role' => 'wizard',
            '--password' => 'a-good-password',
        ])->assertFailed();

        $this->assertSame(1, User::count());
    }
}
