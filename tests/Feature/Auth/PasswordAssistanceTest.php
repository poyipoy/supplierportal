<?php

namespace Tests\Feature\Auth;

use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordAssistanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_receives_generic_assistance_without_account_lookup_tokens_or_mail(): void
    {
        Mail::fake();
        Notification::fake();
        config()->set('support.supplier_email', 'support@example.test');
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->get(route('password.request', [
            'email' => 'private-account@example.test',
            'recipient' => 'attacker@example.test',
            'subject' => 'Injected subject',
            'body' => 'Injected body',
        ]))->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $response->assertSeeText('Password Assistance')
            ->assertSeeText('support@example.test')
            ->assertSeeText('Supplier Portal - Password Assistance Request')
            ->assertSeeText('Company Name: [Company Name]')
            ->assertSeeText('Registered Email: [Registered Email]')
            ->assertSeeText('Copy Email Address')->assertSeeText('Copy Subject')
            ->assertSeeText('Copy Email Template')->assertSeeText('Open Email App')
            ->assertDontSee('private-account@example.test')
            ->assertDontSee('attacker@example.test')
            ->assertDontSee('Injected subject')->assertDontSee('Injected body');
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/\b(users|password_reset_tokens)\b/i', $sql);
        }
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($response->getContent());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        $this->assertCount(1, $xpath->query('//h1'));
        $this->assertSame('Password Assistance', trim($xpath->query('//h1')->item(0)->textContent));
        $this->assertCount(0, $xpath->query('//form|//input[@name="email"]|//*[@autofocus]'));
        $this->assertCount(3, $xpath->query('//button[@type="button"][@data-password-assistance-copy]'));
        $this->assertCount(1, $xpath->query('//*[@aria-live="polite"][@role="status"]'));
        $mailto = $xpath->query('//a[starts-with(@href,"mailto:")]');
        $this->assertCount(1, $mailto);
        $href = $mailto->item(0)->getAttribute('href');
        $this->assertStringStartsWith('mailto:support@example.test?subject=', $href);
        parse_str(substr($href, strpos($href, '?') + 1), $parameters);
        $this->assertSame('Supplier Portal - Password Assistance Request', $parameters['subject']);
        $this->assertStringContainsString("Dear Support Team,\n\n", $parameters['body']);
        $this->assertStringContainsString('Contact Person: [Contact Person]', $parameters['body']);
    }

    public function test_support_placeholder_is_defined_in_config(): void
    {
        $this->assertSame('supplier.support@example.com', config('support.supplier_email'));
    }

    public function test_invalid_support_destination_never_creates_an_active_mailto_link(): void
    {
        foreach (["support@example.test\r\nBcc:attacker@example.test", 'javascript:alert(1)', 'not-an-email', '<script>alert(1)</script>'] as $email) {
            config()->set('support.supplier_email', $email);
            $this->get('/forgot-password')->assertOk()
                ->assertDontSee('href="mailto:', false)
                ->assertDontSee('<script>alert(1)</script>', false)
                ->assertSeeText('Please contact your company representative for support contact details.');
        }
    }
}
