<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_receive_jwt_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Alice Smith',
            'email' => 'alice@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user' => ['id', 'name', 'email', 'created_at'],
                    'access_token',
                    'token_type',
                    'expires_in',
                ],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'alice@example.com');

        $this->assertDatabaseHas('users', ['email' => 'alice@example.com']);
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        User::factory()->create([
            'email' => 'bob@example.com',
            'password' => Hash::make('SecretPass123'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'bob@example.com',
            'password' => 'SecretPass123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['user', 'access_token', 'token_type', 'expires_in'],
            ]);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'bob@example.com',
            'password' => Hash::make('SecretPass123'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'bob@example.com',
            'password' => 'WrongPassword',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_authenticated_user_can_fetch_profile_and_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);

        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.email', $user->email);
    }
}
