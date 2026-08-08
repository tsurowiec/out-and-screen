<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WelcomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_see_the_splash_screen(): void
    {
        $this->get('/')
            ->assertStatus(200)
            ->assertSee('Out&Screen', false);
    }

    public function test_authenticated_users_are_redirected_to_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertRedirect(route('dashboard'));
    }
}
