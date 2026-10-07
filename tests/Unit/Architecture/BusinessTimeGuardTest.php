<?php

namespace Tests\Unit\Architecture;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * PR-6 / Fase 4 — Architecture Guardrail
 *
 * Ensures no app/ code uses bare `today()`, `Carbon::today()`, or calendar-aware
 * `now()->year|month|day|toDateString|startOfDay|endOfDay|startOfMonth|endOfMonth|format`
 * without routing through BusinessTime — or without an explicit `// biz-time:ignore <reason>` annotation.
 *
 * Also ensures no Blade view hard-codes the "WIB" label after a Carbon template expression
 * (the label must come from `BusinessTime::label()` via @bizdt or the bizdt() helper).
 */
class BusinessTimeGuardTest extends TestCase
{
    /**
     * Patterns that are forbidden in app/ PHP unless suppressed by // biz-time:ignore.
     *
     * Rules:
     * - Bare `today()` — the global Carbon helper (not `BusinessTime::today()`)
     * - `Carbon::today(` — the Carbon static helper
     * - `now()->` or `Carbon::now()->` followed by calendar-extracting methods
     *   (year|month|day|toDateString|startOfDay|endOfDay|startOfMonth|endOfMonth|format)
     *
     * Deliberately NOT matched:
     * - `BusinessTime::today()` (correct usage — the `::` after a word char is excluded)
     * - `BusinessTime::now()->format(...)` (correct usage — same reason)
     * - DocBlock / comment references (excluded by the calling loop via `biz-time:ignore`)
     */
    private const FORBIDDEN_PHP = [
        // Bare global today() — must not be preceded by :: (class method call)
        '/(?<![:\w])today\(\)(?!\s*:)/',
        // Carbon::today( — always forbidden regardless of context
        '/Carbon::today\(/',
        // now()-> or Carbon::now()-> followed by calendar properties/methods
        // Exclude BusinessTime::now() by requiring now() NOT be preceded by a word char or ::
        '/(?<![:\w])now\(\)->(?:year|month|day|toDateString|startOfDay|endOfDay|startOfMonth|endOfMonth|format)\b/',
        // Carbon::now()-> calendar methods
        '/Carbon::now\(\)->(?:year|month|day|toDateString|startOfDay|endOfDay|startOfMonth|endOfMonth|format)\b/',
    ];

    public function test_app_code_uses_business_time_for_calendar_logic(): void
    {
        $violations = [];

        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            // BusinessTime.php itself is allowed to reference the forbidden patterns
            if (str_contains($file->getRelativePathname(), 'BusinessTime.php')) {
                continue;
            }

            foreach (file($file->getRealPath()) as $i => $line) {
                // Lines annotated with biz-time:ignore are explicitly exempted
                if (str_contains($line, 'biz-time:ignore')) {
                    continue;
                }

                foreach (self::FORBIDDEN_PHP as $re) {
                    if (preg_match($re, $line)) {
                        $violations[] = $file->getRelativePathname().':'.($i + 1).'  '.trim($line);
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Use BusinessTime instead of bare today()/now()->calendar helpers:\n".implode("\n", $violations)
        );
    }

    public function test_views_do_not_hardcode_timezone_label(): void
    {
        $violations = [];

        foreach (Finder::create()->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
            if (preg_match('/}}\s*WIB\b/', $file->getContents())) {
                $violations[] = $file->getRelativePathname();
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Hard-coded 'WIB' label found after Blade expression. Use \$tzLabel / BusinessTime::label() instead:\n".implode("\n", $violations)
        );
    }
}
