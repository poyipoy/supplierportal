<?php

namespace Tests\Feature;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\DB;
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

        $this->assertSame('adasi_portal_test', $activeDb, 'Testing configuration must target the dedicated adasi_portal_test database.');
        $this->assertSame('adasi_portal_test', DB::selectOne('SELECT DATABASE() AS db')->db, 'The live testing connection must target adasi_portal_test.');
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
