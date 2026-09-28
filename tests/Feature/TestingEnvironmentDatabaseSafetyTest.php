<?php

namespace Tests\Feature;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

class TestingEnvironmentDatabaseSafetyTest extends TestCase
{
    public function test_testing_environment_targets_testing_database(): void
    {
        $activeDb = (string) config('database.connections.mysql.database');

        $this->assertNotEquals('adasi_portal', $activeDb, 'Testing environment must never target the local adasi_portal database.');
        $this->assertTrue(
            str_ends_with($activeDb, '_test') || str_ends_with($activeDb, '_testing'),
            "Testing database '{$activeDb}' must end with '_test' or '_testing'."
        );
    }

    public function test_destructive_migration_aborts_if_attempted_against_local_database_in_testing_mode(): void
    {
        // Simulate a scenario where DB was misconfigured to adasi_portal
        config(['database.connections.mysql.database' => 'adasi_portal']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Testing migrations MUST target a test database');

        $input = new ArrayInput([]);
        $output = new NullOutput;
        Event::dispatch(new CommandStarting('migrate:fresh', $input, $output));
    }
}
