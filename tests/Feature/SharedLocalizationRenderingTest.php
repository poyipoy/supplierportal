<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\PasswordAssistanceController;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class SharedLocalizationRenderingTest extends TestCase
{
    public function test_password_assistance_explicit_indonesian_subject_and_template_preserve_support_destination(): void
    {
        config(['support.supplier_email' => 'support@example.test']);
        app()->setLocale('id');
        $view = app(PasswordAssistanceController::class)->show();
        $data = $view->getData();
        $this->assertSame('support@example.test', $data['supportEmail']);
        $this->assertSame('Supplier Portal - Permintaan Bantuan Kata Sandi', $data['subject']);
        $this->assertStringContainsString('Yth. Tim Dukungan,', $data['template']);
        $this->assertStringContainsString("\n\n", $data['template']);
        $this->assertStringStartsWith('mailto:support@example.test?', $data['mailto']);
        $this->assertStringContainsString('Bantuan Kata Sandi', $view->render());
    }

    public function test_indonesian_shared_file_component_copy_keeps_business_filename_as_plain_text(): void
    {
        app()->setLocale('id');
        $rendered = $this->blade('<x-ui.file-upload name="invoice" label="Invoice" :existing-files="$files" />', ['files' => [['name' => '<img onerror=alert(1)>', 'id' => 1]]]);
        $rendered->assertSee('Pilih berkas')->assertSee('Ganti Berkas');
        $rendered->assertDontSee('<img onerror=alert(1)>', false);
    }

    public function test_error_pages_render_both_locales_with_escaped_messages(): void
    {
        $headings = ['403' => 'forbidden_title', '404' => 'not_found_title', '419' => 'expired_title', '429' => null, '500' => 'server_title'];
        foreach (['en', 'id'] as $locale) {
            app()->setLocale($locale);
            foreach ($headings as $code => $key) {
                $html = view('errors.'.$code, ['exception' => new \RuntimeException('<img onerror=alert(1)>')])->render();
                $this->assertStringContainsString('<html lang="'.$locale.'"', $html);
                $heading = $key ? __('common.review.'.$key) : __('auth.rate_limit.heading');
                $this->assertStringContainsString($heading, $html);
                $this->assertStringNotContainsString('<img onerror=alert(1)>', $html);
                $this->assertStringNotContainsString('common.review.', $html);
            }
        }
    }

    public function test_shared_status_and_chat_loading_fallbacks_follow_the_account_locale(): void
    {
        Auth::setUser(new User(['role' => 'purchasing']));
        $conversationSource = file_get_contents(resource_path('views/conversations/show.blade.php'));
        $this->assertNotFalse($conversationSource);
        $this->assertStringContainsString("window.AdasiI18n.t('js.chat.unknown_sender')", $conversationSource);
        $this->assertStringContainsString("window.AdasiI18n.locale === 'id' ? 'id-ID' : 'en-GB'", $conversationSource);

        foreach (['en', 'id'] as $locale) {
            app()->setLocale($locale);
            foreach ([
                'session-not-found' => __('security.session_not_found'),
                'session-revoked' => __('security.device_signed_out'),
            ] as $status => $expectedMessage) {
                session(['status' => $status]);
                $alerts = view('partials.alerts')->render();
                $this->assertStringContainsString($expectedMessage, $alerts);
                $this->assertStringNotContainsString($status, $alerts);
            }

            $chat = Blade::render("@include('partials.chat-drawer') @stack('scripts')");
            $this->assertStringContainsString(__('datatables.processing'), $chat);
            $this->assertStringNotContainsString('</span>Processing', $chat);
            $this->assertSame($locale === 'id' ? 'Dibaca :time' : 'Read :time', trans('js.chat.read_at', [], $locale));
            $this->assertSame($locale === 'id' ? 'Pengirim tidak dikenal' : 'Unknown sender', trans('js.chat.unknown_sender', [], $locale));
        }

        $drawerSource = file_get_contents(resource_path('views/partials/chat-drawer.blade.php'));
        $this->assertNotFalse($drawerSource);
        $this->assertStringContainsString("receipt.read_at_display ? 'js.chat.read_at' : 'js.chat.read'", $drawerSource);
        $this->assertStringContainsString("window.AdasiI18n.locale === 'id' ? 'id-ID' : 'en-GB'", $drawerSource);
        $this->assertStringNotContainsString('`Read${', $drawerSource);
    }
}
