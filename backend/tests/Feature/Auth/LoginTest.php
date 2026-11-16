<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email' => 'counsel@lexora.test', 'password' => 'Correct-Horse-42']);
    }

    #[Test]
    public function an_admin_can_log_in_with_valid_credentials(): void
    {
        $admin = $this->admin();

        $this->fromFrontend()
            ->postJson('/api/auth/login', ['email' => 'counsel@lexora.test', 'password' => 'Correct-Horse-42'])
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => 'counsel@lexora.test',
                'role' => 'admin',
            ]]);

        $this->assertAuthenticatedAs($admin, 'web');
    }

    #[Test]
    public function login_email_is_case_insensitive(): void
    {
        $this->admin();

        $this->fromFrontend()
            ->postJson('/api/auth/login', ['email' => '  Counsel@LEXORA.test ', 'password' => 'Correct-Horse-42'])
            ->assertOk();
    }

    #[Test]
    public function the_session_id_is_regenerated_after_login(): void
    {
        $this->admin();
        $this->startSession();
        $before = session()->getId();

        $this->fromFrontend()
            ->postJson('/api/auth/login', ['email' => 'counsel@lexora.test', 'password' => 'Correct-Horse-42'])
            ->assertOk();

        $this->assertNotSame($before, session()->getId());
    }

    #[Test]
    public function an_incorrect_password_is_rejected_without_revealing_which_field_was_wrong(): void
    {
        $this->admin();

        $this->fromFrontend()
            ->postJson('/api/auth/login', ['email' => 'counsel@lexora.test', 'password' => 'wrong-password'])
            ->assertStatus(422)
            ->assertExactJson([
                'message' => 'These credentials do not match our records.',
                'errors' => ['email' => ['These credentials do not match our records.']],
            ]);

        $this->assertGuest('web');
    }

    #[Test]
    public function an_unknown_email_gets_the_same_generic_error(): void
    {
        $this->fromFrontend()
            ->postJson('/api/auth/login', ['email' => 'nobody@lexora.test', 'password' => 'whatever'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
    }

    #[Test]
    public function missing_fields_return_consistent_validation_errors(): void
    {
        $this->fromFrontend()
            ->postJson('/api/auth/login', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['email', 'password']])
            ->assertJsonPath('errors.password.0', 'The password field is required.');
    }

    #[Test]
    public function login_is_locked_after_five_failed_attempts_and_reports_retry_after(): void
    {
        $this->admin();

        for ($i = 0; $i < 5; $i++) {
            $this->fromFrontend()
                ->postJson('/api/auth/login', ['email' => 'counsel@lexora.test', 'password' => 'wrong'])
                ->assertStatus(422);
        }

        // Even the correct password is refused while locked out.
        $response = $this->fromFrontend()
            ->postJson('/api/auth/login', ['email' => 'counsel@lexora.test', 'password' => 'Correct-Horse-42'])
            ->assertStatus(429);

        $this->assertStringContainsString('Too many login attempts', $response->json('message'));
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertGuest('web');
    }

    #[Test]
    public function the_login_route_is_throttled_per_ip_across_different_emails(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->fromFrontend()
                ->postJson('/api/auth/login', ['email' => "user{$i}@lexora.test", 'password' => 'wrong'])
                ->assertStatus(422);
        }

        $this->fromFrontend()
            ->postJson('/api/auth/login', ['email' => 'another@lexora.test', 'password' => 'wrong'])
            ->assertStatus(429);
    }

    #[Test]
    public function a_successful_login_resets_the_failed_attempt_counter(): void
    {
        $this->admin();

        for ($i = 0; $i < 4; $i++) {
            $this->fromFrontend()->postJson('/api/auth/login', ['email' => 'counsel@lexora.test', 'password' => 'wrong']);
        }
        $this->fromFrontend()
            ->postJson('/api/auth/login', ['email' => 'counsel@lexora.test', 'password' => 'Correct-Horse-42'])
            ->assertOk();

        $this->app['auth']->forgetGuards();

        for ($i = 0; $i < 4; $i++) {
            $this->fromFrontend()
                ->postJson('/api/auth/login', ['email' => 'counsel@lexora.test', 'password' => 'wrong'])
                ->assertStatus(422);
        }
    }

    #[Test]
    public function session_login_is_refused_for_requests_not_coming_from_the_spa(): void
    {
        $this->admin();

        // No Origin/Referer from a stateful domain => no session => no cookie login.
        $this->postJson('/api/auth/login', ['email' => 'counsel@lexora.test', 'password' => 'Correct-Horse-42'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Session authentication is only available to the Lexora web application.');
    }

    #[Test]
    public function the_api_never_returns_the_password_hash_or_remember_token(): void
    {
        $this->admin();

        $json = $this->fromFrontend()
            ->postJson('/api/auth/login', ['email' => 'counsel@lexora.test', 'password' => 'Correct-Horse-42'])
            ->getContent();

        $this->assertStringNotContainsString('password', $json);
        $this->assertStringNotContainsString('remember_token', $json);
    }
}
