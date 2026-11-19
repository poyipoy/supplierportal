<?php

namespace Tests\Feature;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    public function test_custom_404_page_renders_plain_without_background_photo(): void
    {
        $response = $this->get('/non-existent-page-adasi-404');
        $response->assertStatus(404)
            ->assertSee(__('common.review.not_found_title', [], 'en'))
            ->assertSee('Error 404')
            ->assertSee('auth-shell--plain', false)
            ->assertDontSee('adasi-login-bg.jpg', false)
            ->assertDontSee('<aside class="auth-brand-panel"', false)
            ->assertDontSee('class="auth-mobile-brand"', false)
            ->assertDontSee('<div class="auth-footer-copy">', false);
    }

    public function test_custom_403_page_view_exists_and_renders(): void
    {
        $view = $this->view('errors.403', [
            'exception' => new HttpException(403, 'Akses Ditolak'),
        ]);

        $view->assertSee('Akses Ditolak')
            ->assertSee('Error 403')
            ->assertSee('auth-shell--plain', false)
            ->assertDontSee('adasi-login-bg.jpg', false)
            ->assertDontSee('<aside class="auth-brand-panel"', false);
    }

    public function test_custom_419_page_view_exists_and_renders(): void
    {
        $view = $this->view('errors.419');

        $view->assertSee(__('common.review.expired_title', [], 'en'))
            ->assertSee('Error 419')
            ->assertSee('auth-shell--plain', false)
            ->assertDontSee('adasi-login-bg.jpg', false)
            ->assertDontSee('<aside class="auth-brand-panel"', false);
    }

    public function test_custom_500_page_view_exists_and_renders(): void
    {
        $view = $this->view('errors.500');

        $view->assertSee(__('common.review.server_title', [], 'en'))
            ->assertSee('Error 500')
            ->assertSee('auth-shell--plain', false)
            ->assertDontSee('adasi-login-bg.jpg', false)
            ->assertDontSee('<aside class="auth-brand-panel"', false);
    }

    public function test_custom_401_405_and_503_page_views_render_plain(): void
    {
        $this->view('errors.401')
            ->assertSee(__('common.review.unauthorized_title', [], 'en'))
            ->assertSee('Error 401')
            ->assertSee('auth-shell--plain', false)
            ->assertDontSee('adasi-login-bg.jpg', false)
            ->assertDontSee('<aside class="auth-brand-panel"', false);

        $this->view('errors.405')
            ->assertSee(__('common.review.method_not_allowed_title', [], 'en'))
            ->assertSee('Error 405')
            ->assertSee('auth-shell--plain', false)
            ->assertDontSee('adasi-login-bg.jpg', false)
            ->assertDontSee('<aside class="auth-brand-panel"', false);

        $this->view('errors.503')
            ->assertSee(__('common.review.service_unavailable_title', [], 'en'))
            ->assertSee('Error 503')
            ->assertSee('auth-shell--plain', false)
            ->assertDontSee('adasi-login-bg.jpg', false)
            ->assertDontSee('<aside class="auth-brand-panel"', false);
    }

    public function test_maintenance_global_and_auth_block_views_render_plain(): void
    {
        $this->view('maintenance.global', ['notices' => collect()])
            ->assertSee('data-maintenance="global"', false)
            ->assertSee('auth-shell--plain', false)
            ->assertDontSee('adasi-login-bg.jpg', false)
            ->assertDontSee('<aside class="auth-brand-panel"', false)
            ->assertDontSee('<div class="auth-footer-copy">', false);

        $this->view('auth.rate-limited', [
            'retryAfter' => 30,
            'returnUrl' => route('login'),
            'returnLabel' => __('auth.sso.back_to_login'),
        ])
            ->assertSee('auth-shell--plain', false)
            ->assertDontSee('adasi-login-bg.jpg', false)
            ->assertDontSee('<aside class="auth-brand-panel"', false);

        $this->view('auth.sso-error', ['message' => 'SSO failed'])
            ->assertSee('SSO failed')
            ->assertSee('auth-shell--plain', false)
            ->assertDontSee('adasi-login-bg.jpg', false)
            ->assertDontSee('<aside class="auth-brand-panel"', false);
    }
}
