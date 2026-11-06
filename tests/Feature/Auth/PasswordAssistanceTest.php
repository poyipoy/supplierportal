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

        $xpath = $this->xpathFor($response->getContent());
        $this->assertCount(1, $xpath->query('//h1'));
        $this->assertSame('Password Assistance', trim($xpath->query('//h1')->item(0)->textContent));
        $this->assertCount(0, $xpath->query('//form|//input[@name="email"]|//*[@autofocus]'));
        $this->assertCount(3, $xpath->query('//button[@type="button"][@data-password-assistance-copy]'));
        $this->assertCount(1, $xpath->query('//*[@aria-live="polite"][@role="status"]'));
        // Manual-copy details never render inline: one trigger opens a closed popup that holds the copy controls and the only status region.
        $this->assertCount(0, $xpath->query('//details'));
        $this->assertCount(1, $xpath->query('//button[@aria-haspopup="dialog"][contains(@class,"tw:border-transparent")]'));
        $dialog = $xpath->query('//*[@role="dialog"][@aria-modal="true"]');
        $this->assertCount(1, $dialog);
        $this->assertSame('Copy the details manually', trim($xpath->evaluate('string(//*[@id="'.$dialog->item(0)->getAttribute('aria-labelledby').'"])')));
        $this->assertStringContainsString('open: false', $xpath->query('ancestor::*[@x-data][1]', $dialog->item(0))->item(0)->getAttribute('x-data'));
        $this->assertCount(3, $xpath->query('//*[@role="dialog"]//button[@data-password-assistance-copy]'));
        $this->assertCount(1, $xpath->query('//*[@role="dialog"]//*[@role="status"]'));
        $this->assertCount(1, $xpath->query('//*[@role="dialog"]//button[@aria-label="Close dialog"]'));
        $mailto = $xpath->query('//a[starts-with(@href,"mailto:")]');
        $this->assertCount(1, $mailto);
        $href = $mailto->item(0)->getAttribute('href');
        $this->assertStringStartsWith('mailto:support@example.test?subject=', $href);
        parse_str(substr($href, strpos($href, '?') + 1), $parameters);
        $this->assertSame('Supplier Portal - Password Assistance Request', $parameters['subject']);
        $this->assertStringContainsString("Dear Support Team,\n\n", $parameters['body']);
        $this->assertStringContainsString('Contact Person: [Contact Person]', $parameters['body']);

        // Simplified layout: no email sub-heading/help copy, WhatsApp is one outline button with the vector logo.
        $response->assertDontSeeText('Get help by email')->assertDontSeeText('082136753958');
        $whatsapp = $xpath->query('//a[@data-password-assistance-whatsapp]');
        $this->assertCount(1, $whatsapp);
        $link = $whatsapp->item(0);
        $this->assertSame('_blank', $link->getAttribute('target'));
        $this->assertStringContainsString('noopener', $link->getAttribute('rel'));
        $this->assertStringStartsWith('https://wa.me/6282136753958?text=', $link->getAttribute('href'));
        parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $whatsappQuery);
        $this->assertStringContainsString('I need help resetting my Supplier Portal password.', $whatsappQuery['text']);
        $this->assertStringContainsString("Company Name: [Company Name]\nRegistered Email: [Registered Email]", $whatsappQuery['text']);
        $this->assertCount(1, $xpath->query('.//*[@aria-hidden="true"]//svg[.//circle]', $link), 'WhatsApp logo is an inline decorative SVG.');
        $this->assertSame('Chat on WhatsApp (opens in a new tab)', preg_replace('/\s+/', ' ', trim($xpath->evaluate('string(.)', $link))));
    }

    public function test_invalid_whatsapp_destination_never_renders_a_whatsapp_link(): void
    {
        config()->set('support.supplier_email', '');
        foreach (['javascript:alert(1)', 'http://wa.me/6282136753958', 'https://wa.me.attacker.test/6282136753958', null] as $url) {
            config()->set('support.supplier_whatsapp_url', $url);
            $this->get('/forgot-password')->assertOk()
                ->assertDontSee('data-password-assistance-whatsapp', false)
                ->assertDontSee('wa.me', false)
                ->assertDontSee('javascript:', false)
                ->assertSeeText('Please contact your company representative for support contact details.');
        }
    }

    private function xpathFor(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }

    public function test_support_email_defaults_to_the_adasi_contact(): void
    {
        $this->assertSame('raihan.ramadhan@astra-daido.co.id', config('support.supplier_email'));
    }

    public function test_invalid_support_destination_never_creates_an_active_mailto_link(): void
    {
        foreach (["support@example.test\r\nBcc:attacker@example.test", 'javascript:alert(1)', 'not-an-email', '<script>alert(1)</script>'] as $email) {
            config()->set('support.supplier_email', $email);
            $response = $this->get('/forgot-password')->assertOk();
            // With no mailto CTA the manual trigger becomes the primary path, and the popup stays closed on load.
            $xpath = $this->xpathFor($response->getContent());
            $this->assertCount(1, $xpath->query('//button[@aria-haspopup="dialog"][contains(@class,"tw:bg-primary ")]'));
            $dialog = $xpath->query('//*[@role="dialog"][@aria-modal="true"]')->item(0);
            $this->assertNotNull($dialog);
            $this->assertStringContainsString('open: false', $xpath->query('ancestor::*[@x-data][1]', $dialog)->item(0)->getAttribute('x-data'));
            $response
                ->assertDontSee('href="mailto:', false)
                ->assertDontSee('<script>alert(1)</script>', false)
                ->assertSeeText('Chat on WhatsApp')
                ->assertSee('href="https://wa.me/6282136753958?text=', false);
        }
    }
}
