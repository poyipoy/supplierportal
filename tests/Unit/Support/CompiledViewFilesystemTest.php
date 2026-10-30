<?php

namespace Tests\Unit\Support;

use App\Support\View\CompiledViewFilesystem;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

/**
 * Windows refuses to rename a temp file over a compiled view while another
 * handle is open on it ("Access is denied (code: 5)"), which used to turn a
 * concurrent compile into a 500 and strand a *.tmp in storage/framework/views.
 */
class CompiledViewFilesystemTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'compiled-views-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_replace_publishes_the_content_without_leaving_a_temp_file(): void
    {
        $target = $this->target('old');

        $this->filesystem(failures: 0)->replace($target, 'new');

        $this->assertSame('new', file_get_contents($target));
        $this->assertNoTempFiles();
    }

    public function test_replace_retries_while_the_destination_is_held_open(): void
    {
        $target = $this->target('old');
        $filesystem = $this->filesystem(failures: 2);

        $filesystem->replace($target, 'new');

        $this->assertSame('new', file_get_contents($target));
        $this->assertSame(3, $filesystem->swapAttempts);
        $this->assertSame(2, $filesystem->pauses);
        $this->assertNoTempFiles();
    }

    public function test_replace_stops_when_another_process_already_published_identical_content(): void
    {
        $target = $this->target('same');
        $filesystem = $this->filesystem(failures: PHP_INT_MAX);

        $filesystem->replace($target, 'same');

        $this->assertSame('same', file_get_contents($target));
        $this->assertSame(1, $filesystem->swapAttempts);
        $this->assertSame(0, $filesystem->pauses);
        $this->assertNoTempFiles();
    }

    public function test_replace_gives_up_cleanly_when_the_destination_never_becomes_available(): void
    {
        $target = $this->target('old');
        $filesystem = $this->filesystem(failures: PHP_INT_MAX);

        try {
            $filesystem->replace($target, 'new');
            $this->fail('Expected the publish to fail once the retries are exhausted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Unable to publish compiled view', $e->getMessage());
        }

        $this->assertSame('old', file_get_contents($target));
        $this->assertGreaterThan(1, $filesystem->swapAttempts);
        $this->assertSame($filesystem->swapAttempts - 1, $filesystem->pauses);
        $this->assertNoTempFiles();
    }

    /**
     * On Windows the first rename really fails here (the handle blocks it), so
     * this is the real-world scenario; elsewhere the rename succeeds at once.
     */
    public function test_replace_survives_a_real_open_handle_on_the_destination(): void
    {
        $target = $this->target('old');
        $handle = fopen($target, 'rb');

        $filesystem = new class($handle) extends CompiledViewFilesystem
        {
            public function __construct(private $handle) {}

            protected function pause(int $attempt): void
            {
                if (is_resource($this->handle)) {
                    fclose($this->handle);
                }
            }
        };

        try {
            $filesystem->replace($target, 'new');
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $this->assertSame('new', file_get_contents($target));
        $this->assertNoTempFiles();
    }

    public function test_the_blade_compiler_publishes_through_the_resilient_filesystem(): void
    {
        $compiler = $this->app->make('blade.compiler');

        $this->assertInstanceOf(BladeCompiler::class, $compiler);
        $this->assertInstanceOf(
            CompiledViewFilesystem::class,
            (new ReflectionProperty($compiler, 'files'))->getValue($compiler)
        );
        $this->assertArrayHasKey('dynamic-component', $compiler->getClassComponentAliases());
    }

    private function target(string $content): string
    {
        $path = $this->dir.DIRECTORY_SEPARATOR.'ad26'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * A filesystem whose first $failures rename attempts report failure, with
     * the retry delay removed so the tests stay fast.
     *
     * @return CompiledViewFilesystem&object{swapAttempts: int, pauses: int}
     */
    private function filesystem(int $failures): CompiledViewFilesystem
    {
        return new class($failures) extends CompiledViewFilesystem
        {
            public int $swapAttempts = 0;

            public int $pauses = 0;

            public function __construct(private int $failures) {}

            protected function swapIn(string $from, string $to): bool
            {
                $this->swapAttempts++;

                if ($this->failures > 0) {
                    $this->failures--;

                    return false;
                }

                return parent::swapIn($from, $to);
            }

            protected function pause(int $attempt): void
            {
                $this->pauses++;
            }
        };
    }

    private function assertNoTempFiles(): void
    {
        $this->assertSame([], glob($this->dir.DIRECTORY_SEPARATOR.'*.tmp'));
    }
}
