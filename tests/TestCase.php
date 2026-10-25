<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // Ensure Docker Compose container environment variables do not override phpunit.xml testing configuration
        $testingEnv = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
        ];

        // Ensure a valid 256-bit JWT_SECRET is always available during testing even if `jwt:secret` was not run
        if (empty(getenv('JWT_SECRET'))) {
            $testingEnv['JWT_SECRET'] = 'testing_jwt_secret_key_that_is_at_least_64_characters_long_1234567890';
        }

        foreach ($testingEnv as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        parent::setUp();
    }
}
