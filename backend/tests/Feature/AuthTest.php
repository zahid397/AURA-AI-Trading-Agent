<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_a_user_and_settings_row_and_starts_a_session(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New Trader',
            'email' => 'newtrader@example.com',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.email', 'newtrader@example.com');
        $response->assertJsonPath('data.settings.mode', 'approval_required');
        $response->assertJsonMissing(['password']);

        $user = User::whereEmail('newtrader@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('user_settings', ['user_id' => $user->id, 'mode' => 'approval_required']);
    }

    public function test_register_hashes_the_password_rather_than_storing_it_plain(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'New Trader',
            'email' => 'newtrader@example.com',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertCreated();

        $user = User::whereEmail('newtrader@example.com')->firstOrFail();
        $this->assertNotEquals('a-strong-password', $user->password);
        $this->assertTrue(Hash::check('a-strong-password', $user->password));
    }

    public function test_register_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'trader@example.com']);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Someone Else',
            'email' => 'trader@example.com',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertGuest();
    }

    public function test_register_rejects_a_mismatched_password_confirmation(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New Trader',
            'email' => 'newtrader@example.com',
            'password' => 'a-strong-password',
            'password_confirmation' => 'does-not-match',
        ]);

        $response->assertStatus(422);
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'newtrader@example.com']);
    }

    public function test_register_requires_name_email_and_password(): void
    {
        $response = $this->postJson('/api/v1/auth/register', []);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        // This app's exception handler nests field errors under
        // error.details.fields (see bootstrap/app.php's ValidationException
        // branch), not Laravel's default top-level `errors` key -- so
        // assertJsonValidationErrors() doesn't apply here; check the actual
        // envelope shape instead, matching the rest of this file's convention.
        $response->assertJsonPath('error.details.fields.name.0', 'The name field is required.');
        $response->assertJsonPath('error.details.fields.email.0', 'The email field is required.');
        $response->assertJsonPath('error.details.fields.password.0', 'The password field is required.');
    }

    public function test_register_is_rate_limited_after_repeated_attempts(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/register', [
                'name' => 'New Trader',
                'email' => "trader{$i}@example.com",
                'password' => 'a-strong-password',
                'password_confirmation' => 'a-strong-password',
            ])->assertCreated();
        }

        $this->postJson('/api/v1/auth/register', [
            'name' => 'New Trader',
            'email' => 'trader-over-limit@example.com',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertStatus(429);
    }

    public function test_login_succeeds_with_valid_credentials_and_starts_a_session(): void
    {
        $user = User::factory()->create(['email' => 'trader@example.com']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'trader@example.com',
            'password' => 'password',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.email', 'trader@example.com');
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_an_invalid_password(): void
    {
        User::factory()->create(['email' => 'trader@example.com']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'trader@example.com',
            'password' => 'not-the-right-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertGuest();
    }

    public function test_login_fails_for_an_unknown_email(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(422);
        $this->assertGuest();
    }

    public function test_login_requires_email_and_password(): void
    {
        $response = $this->postJson('/api/v1/auth/login', []);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_login_is_rate_limited_after_repeated_failures(): void
    {
        User::factory()->create(['email' => 'trader@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'trader@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'trader@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_me_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/me');

        $response->assertOk();
        $response->assertJsonPath('data.id', $user->id);
        $response->assertJsonPath('data.email', $user->email);
        $response->assertJsonMissing(['password']);
    }

    public function test_logout_clears_the_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        // Assert against the 'web' guard explicitly: the 'sanctum' guard
        // (used by the auth:sanctum middleware) caches its resolved user
        // for the lifetime of the request/test process once
        // authenticated, independently of the 'web' session guard this
        // controller actually logs out — a testing-only artifact of
        // guards persisting across simulated requests in the same
        // process, not something a real subsequent browser request (with
        // no valid session cookie) would exhibit.
        $this->assertGuest('web');
    }

    public function test_every_response_carries_a_request_id_header(): void
    {
        $response = $this->getJson('/api/v1/me');

        $response->assertHeader('X-Request-Id');
    }
}
