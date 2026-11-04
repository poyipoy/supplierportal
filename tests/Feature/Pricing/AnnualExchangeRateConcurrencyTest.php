<?php

namespace Tests\Feature\Pricing;

use App\Models\AnnualExchangeRate;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AnnualExchangeRateConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected $exceptTables = ['local_po_document_capacity'];

    protected function beforeTruncatingDatabase(): void
    {
        $this->assertMatchesRegularExpression('/_(test|testing)(_test_\d+)?$/', DB::selectOne('SELECT DATABASE() AS db')->db);
    }

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public function test_simultaneous_initial_saves_and_corrections_create_one_complete_revision(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        foreach ([0, 1] as $expectedRevision) {
            $start = (string) (microtime(true) + 3);
            $processes = [];
            foreach (['17200', '18000'] as $usd) {
                $processes[] = new Process([PHP_BINARY, base_path('tests/Support/annual-exchange-rates-worker.php'), (string) $actor->id, $start, (string) $expectedRevision, $usd], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => DB::connection()->getDatabaseName(),
                    'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => (string) config('database.connections.mysql.port'),
                    'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'), 'DB_URL' => '',
                    'HASHIDS_SALT' => 'adasi-isolated-test-salt-never-use-in-production', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
                ], timeout: 30);
            }
            foreach ($processes as $process) {
                $process->start();
            }
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            }
            $this->assertEqualsCanonicalizing(['accepted', 'conflict'], array_map(fn ($process) => trim($process->getOutput()), $processes));
            $this->assertSame(3, AnnualExchangeRate::where('year', 2024)->where('revision', $expectedRevision + 1)->count());
        }
        $this->assertSame(6, AnnualExchangeRate::count());
    }

    public function test_exhausted_deadlock_retains_the_draft_and_rolls_back_all_currencies(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $event = 'eloquent.creating: '.AnnualExchangeRate::class;
        $attempts = 0;
        Event::listen($event, function (AnnualExchangeRate $rate) use (&$attempts): void {
            if ($rate->currency === 'CNY') {
                $attempts++;
                throw $this->databaseFailure('40001', 1213);
            }
        });
        $payload = ['year' => 2024, 'expected_revision' => 0, 'rates' => ['USD' => '17500', 'JPY' => '106', 'CNY' => '2300']];
        try {
            $response = $this->actingAs($actor)->post(route('admin.exchange-rates.annual.store'), $payload)
                ->assertSessionHasErrors('expected_revision', null, 'annualRates')
                ->assertSessionHasInput('expected_revision', 0)
                ->assertSessionHasInput('rates.USD', '17500');
        } finally {
            Event::forget($event);
        }
        $this->assertSame(3, $attempts);
        $this->assertDatabaseCount('annual_exchange_rates', 0);
        $this->get($response->headers->get('Location'))->assertOk()
            ->assertViewHas('annualEditYear', 2024)
            ->assertViewHas('annualRevision', '0')
            ->assertViewHas('annualInputRates', $payload['rates'])
            ->assertSee('annual_new_year=2024', false);
        $this->get(route('admin.exchange-rates.index', ['tab' => 'annual', 'annual_new' => 1, 'annual_new_year' => 2024]))->assertOk();
    }

    public function test_other_database_failures_are_not_misreported_as_an_annual_conflict(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $event = 'eloquent.creating: '.AnnualExchangeRate::class;
        Event::listen($event, function (AnnualExchangeRate $rate): void {
            if ($rate->currency === 'CNY') {
                throw $this->databaseFailure('HY000', 2006);
            }
        });
        try {
            $this->actingAs($actor)->withoutExceptionHandling()->post(route('admin.exchange-rates.annual.store'), [
                'year' => 2024, 'expected_revision' => 0, 'rates' => ['USD' => '17500', 'JPY' => '106', 'CNY' => '2300'],
            ]);
            $this->fail('Unexpected database failures must propagate.');
        } catch (QueryException $exception) {
            $this->assertSame(2006, $exception->errorInfo[1]);
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('annual_exchange_rates', 0);
    }

    private function databaseFailure(string $state, int $driverCode): QueryException
    {
        $previous = new \PDOException('Synthetic database failure', (int) $state);
        $previous->errorInfo = [$state, $driverCode, 'Synthetic database failure'];

        return new QueryException('mysql', 'insert into annual_exchange_rates', [], $previous);
    }
}
