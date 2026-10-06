<?php

namespace Tests;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Every test class extends this one (do NOT `use RefreshDatabase` again in a test: the trait would override
 * migrateDatabases() below and bring back the seed-per-test slowness).
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Feature tests assert on Inertia props; they must not depend on a built Vite manifest.
        $this->withoutVite();
    }

    /**
     * Runs ONCE per test run: migrate, then seed the reference data (permissions, roles, plans, add-ons, demo accounts)
     * before the per-test transaction starts. Every test begins with the data of a fresh install and whatever it
     * changes is rolled back when the test ends.
     */
    protected function migrateDatabases()
    {
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());

        $this->seed(DatabaseSeeder::class);
    }
}