<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    /**
     * Register a new user and issue a JWT token.
     *
     * @param array{name: string, email: string, password: string} $data
     * @return array{user: User, access_token: string, token_type: string, expires_in: int}
     */
    public function register(array $data): array
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => strtolower(trim($data['email'])),
            'password' => Hash::make($data['password']),
        ]);

        /** @var string $token */
        $token = Auth::guard('api')->login($user);

        return $this->formatTokenPayload($user, $token);
    }

    /**
     * Authenticate a user by credentials and issue a JWT token.
     *
     * @param array{email: string, password: string} $credentials
     * @return array{user: User, access_token: string, token_type: string, expires_in: int}
     * @throws BusinessRuleException
     */
    public function login(array $credentials): array
    {
        $credentials['email'] = strtolower(trim($credentials['email']));

        /** @var string|false $token */
        $token = Auth::guard('api')->attempt($credentials);

        if (! $token) {
            throw new BusinessRuleException('Invalid email or password.', 401);
        }

        /** @var User $user */
        $user = Auth::guard('api')->user();

        return $this->formatTokenPayload($user, $token);
    }

    /**
     * Refresh the authenticated user's JWT token.
     *
     * @return array{user: User, access_token: string, token_type: string, expires_in: int}
     */
    public function refresh(): array
    {
        /** @var User $user */
        $user = Auth::guard('api')->user();

        /** @var string $token */
        $token = Auth::guard('api')->refresh();

        return $this->formatTokenPayload($user, $token);
    }

    /**
     * Log out the authenticated user and invalidate their JWT token.
     */
    public function logout(): void
    {
        Auth::guard('api')->logout();
    }

    /**
     * Format token payload array.
     *
     * @return array{user: User, access_token: string, token_type: string, expires_in: int}
     */
    private function formatTokenPayload(User $user, string $token): array
    {
        /** @var \PHPOpenSourceSaver\JWTAuth\JWTGuard $guard */
        $guard = Auth::guard('api');

        return [
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $guard->factory()->getTTL() * 60,
        ];
    }
}
