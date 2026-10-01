<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAccountNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_menu_has_the_account_actions_in_order_and_preserves_post_logout(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($user)->get(route('profile.edit'))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $trigger = $xpath->query('//button[@aria-label="Open user menu"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $trigger);
        $this->assertSame('dropdown', $trigger->getAttribute('data-bs-toggle'));
        $this->assertSame('false', $trigger->getAttribute('aria-expanded'));

        $menu = $xpath->query('//button[@aria-label="Open user menu"]/following-sibling::ul')->item(0);
        $this->assertInstanceOf(DOMElement::class, $menu);
        $actions = $xpath->query('.//a | .//button[@type="submit"]', $menu);
        $labels = [];
        foreach ($actions as $action) {
            $labels[] = trim($action->textContent);
        }
        $this->assertSame(['My Profile', 'Security', 'Notifications', 'Customization', 'Logout'], $labels);
        $this->assertSame(route('profile.edit'), $actions->item(0)->getAttribute('href'));
        $this->assertSame(route('profile.security'), $actions->item(1)->getAttribute('href'));
        $this->assertSame(route('profile.notifications'), $actions->item(2)->getAttribute('href'));
        $this->assertSame(route('profile.customization'), $actions->item(3)->getAttribute('href'));
        $this->assertStringContainsString($user->email, $menu->textContent);
        $this->assertCount(2, $xpath->query('.//hr', $menu));
        $logout = $xpath->query('.//form', $menu)->item(0);
        $this->assertSame('POST', $logout->getAttribute('method'));
        $this->assertSame(route('logout'), $logout->getAttribute('action'));
        $this->assertCount(1, $xpath->query('.//input[@name="_token"]', $logout));
        $this->assertCount(0, $xpath->query('.//*[@role="menu"]', $menu));
    }

    public function test_security_destination_has_a_dedicated_heading_and_no_obsolete_fragment(): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('profile.security'))->assertOk();
        $heading = $this->xpath($response->getContent())->query('//h1')->item(0);
        $this->assertInstanceOf(DOMElement::class, $heading);
        $this->assertSame('Security', trim($heading->textContent));
        $response->assertDontSee('profile-security-title', false);
    }

    private function xpath(string $html): DOMXPath
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
}
