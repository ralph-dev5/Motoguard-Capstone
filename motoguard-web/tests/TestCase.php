<?php

namespace Tests;

use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    private static bool $databaseGuardPassed = false;

    protected function setUp(): void
    {
        if (! self::$databaseGuardPassed) {
            self::guardAgainstMainDatabase();
            self::$databaseGuardPassed = true;
        }

        parent::setUp();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * RefreshDatabase wipes every table, so tests must never connect to the main Supabase project.
     */
    private static function guardAgainstMainDatabase(): void
    {
        $root = dirname(__DIR__);
        $testing = is_file("{$root}/.env.testing") ? Dotenv::parse((string) file_get_contents("{$root}/.env.testing")) : [];
        $main = is_file("{$root}/.env") ? Dotenv::parse((string) file_get_contents("{$root}/.env")) : [];

        $testUser = $testing['DB_USERNAME'] ?? '';

        if ($testUser === '' || $testUser === ($main['DB_USERNAME'] ?? null)) {
            throw new RuntimeException('Set DB_USERNAME in .env.testing to the separate motoguard-test Supabase project before running tests.');
        }
    }
}
