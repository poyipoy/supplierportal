<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('adasi_portal_test', config('database.connections.mysql.database'));
        $this->assertSame('adasi_portal_test', DB::selectOne('SELECT DATABASE() AS db')->db);
    }

    public function test_one_event_renders_per_recipient_and_broadcast_matches_database_without_translating_user_text(): void
    {
        Queue::fake();
        $english = User::factory()->create(['role' => 'admin']);
        $indonesian = User::factory()->create(['role' => 'admin']);
        $indonesian->preference()->create([...config('user_preferences.defaults'), 'locale' => 'id']);
        app()->setLocale('id');
        $raw = 'notifications.exports.completed.title <b>unchanged</b>';
        $service = app(NotificationService::class);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $service->send([$english, $indonesian], 'phase7.notice', 'phase7:recipient-language', 'notifications.exports.completed.title', 'notifications.exports.completed.message', '#', 'bell', [], ['label' => $raw]);
        }
        $en = $english->notifications()->sole();
        $id = $indonesian->notifications()->sole();
        $this->assertSame('Export Completed', $en->data['title']);
        $this->assertSame('Ekspor Selesai', $id->data['title']);
        $this->assertSame('Export '.$raw.' is ready to download.', $en->data['message']);
        $this->assertSame('Ekspor '.$raw.' siap diunduh.', $id->data['message']);
        $this->assertSame('id', app()->getLocale());
        Queue::assertPushed(BroadcastEvent::class, 2);
        Queue::assertPushed(BroadcastEvent::class, fn ($job) => $job->event->notifiable->id === $indonesian->id && $job->event->data['title'] === $id->data['title'] && $job->event->data['message'] === $id->data['message']);

        $indonesian->preference()->update(['locale' => 'en']);
        $this->assertSame('Ekspor Selesai', $id->fresh()->data['title']);
        $service->send($indonesian, 'phase7.notice', 'phase7:fresh-background-snapshot', 'notifications.exports.completed.title', 'notifications.exports.completed.message', '#', 'bell', [], ['label' => 'Report']);
        $this->assertSame('Export Completed', $indonesian->notifications()->latest('created_at')->latest('id')->firstWhere('data->event_key', 'phase7:fresh-background-snapshot')->data['title']);
    }

    public function test_dynamic_notification_dates_follow_each_recipients_locale(): void
    {
        Queue::fake();
        $english = User::factory()->create(['role' => 'admin']);
        $indonesian = User::factory()->create(['role' => 'admin']);
        $indonesian->preference()->create([...config('user_preferences.defaults'), 'locale' => 'id']);
        app()->setLocale('id');

        app(NotificationService::class)->send(
            [$english, $indonesian],
            'phase7.notice',
            'phase7:recipient-localized-date',
            'claims.copy.new_material_claim',
            'claims.notify.created_body',
            '#',
            'bell',
            [],
            ['po' => 'PO-TEST'],
            ['deadline' => '@date:2026-10-05'],
        );

        $this->assertStringContainsString('05 Oct 2026', $english->notifications()->sole()->data['message']);
        $this->assertStringContainsString('05 Okt 2026', $indonesian->notifications()->sole()->data['message']);
        $this->assertSame('id', app()->getLocale());
        Queue::assertPushed(BroadcastEvent::class, 2);
    }

    public function test_locale_queries_are_batched_and_do_not_run_once_per_key_or_recipient(): void
    {
        Queue::fake();
        $recipients = User::factory()->count(501)->create(['role' => 'admin']);
        $localeQueries = 0;
        DB::listen(function ($query) use (&$localeQueries): void {
            if (str_contains($query->sql, 'user_preferences') && str_contains($query->sql, '`locale`')) {
                $localeQueries++;
            }
        });
        app(NotificationService::class)->send($recipients, 'phase7.notice', 'phase7:batch', 'notifications.exports.failed.title', 'notifications.exports.failed.message');
        $this->assertSame(2, $localeQueries);
        $this->assertSame(501, DB::table('notifications')->where('data->event_key', 'phase7:batch')->count());
    }

    public function test_failed_locale_snapshot_keeps_delivery_and_does_not_leak_sender_locale(): void
    {
        Queue::fake();
        $user = User::factory()->create(['role' => 'admin']);
        $user->preference()->create([...config('user_preferences.defaults'), 'locale' => 'id']);
        app()->setLocale('id');
        $failOnce = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$failOnce): void {
            if ($failOnce && str_contains($sql, 'user_preferences') && str_contains($sql, '`locale`')) {
                $failOnce = false;
                throw new \RuntimeException('Simulated preference lookup failure');
            }
        });
        app(NotificationService::class)->send($user, 'phase7.notice', 'phase7:lookup-failure', 'notifications.exports.completed.title', 'notifications.exports.completed.message', '#', 'bell', [], ['label' => 'Report']);
        $this->assertSame('Export Completed', $user->notifications()->sole()->data['title']);
        $this->assertSame('id', app()->getLocale());
        Queue::assertPushed(BroadcastEvent::class, 1);
    }

    public function test_registered_off_and_silent_delivery_still_use_existing_policy(): void
    {
        Queue::fake();
        $off = User::factory()->create(['role' => 'admin']);
        $silent = User::factory()->create(['role' => 'admin']);
        foreach ([$off, $silent] as $user) {
            $row = $user->preference()->create([...config('user_preferences.defaults'), 'locale' => 'id']);
            $row->forceFill(['notification_preferences' => ['pr_submitted' => $user->id === $off->id ? false : 'silent']])->save();
        }
        app(NotificationService::class)->send([$off, $silent], 'pr.submitted', 'phase7:delivery-policy', 'notifications.exports.completed.title', 'notifications.exports.completed.message', '#', 'bell', [], ['label' => 'PR']);
        $this->assertSame(0, $off->notifications()->count());
        $this->assertSame('Ekspor Selesai', $silent->notifications()->sole()->data['title']);
        $this->assertTrue($silent->notifications()->sole()->data['silent']);
        Queue::assertNothingPushed();
    }
}
