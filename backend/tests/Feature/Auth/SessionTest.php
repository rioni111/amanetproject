<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SessionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guests_cannot_fetch_the_current_user(): void
    {
        $this->fromFrontend()
            ->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    #[Test]
    public function an_authenticated_admin_can_fetch_their_profile(): void
    {
        $admin = User::factory()->create(['name' => 'Ada Counsel']);

        $this->actingAsAdmin($admin)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $admin->id)
            ->assertJsonPath('data.name', 'Ada Counsel')
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonMissingPath('data.password');
    }

    #[Test]
    public function logout_invalidates_the_session(): void
    {
        User::factory()->create(['email' => 'counsel@lexora.test', 'password' => 'Correct-Horse-42']);

        $this->fromFrontend()
            ->postJson('/api/auth/login', ['email' => 'counsel@lexora.test', 'password' => 'Correct-Horse-42'])
            ->assertOk();
        $this->fromFrontend()->getJson('/api/auth/me')->assertOk();

        $sessionId = session()->getId();

        $this->fromFrontend()->postJson('/api/auth/logout')->assertNoContent();

        $this->assertGuest('web');
        $this->assertNotSame($sessionId, session()->getId());

        $this->app['auth']->forgetGuards();
        $this->fromFrontend()->getJson('/api/auth/me')->assertUnauthorized();
        $this->fromFrontend()->getJson('/api/dashboard/summary')->assertUnauthorized();
    }

    #[Test]
    public function guests_cannot_log_out(): void
    {
        $this->fromFrontend()->postJson('/api/auth/logout')->assertUnauthorized();
    }
}
