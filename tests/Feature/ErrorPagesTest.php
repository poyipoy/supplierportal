<?php

namespace Tests\Feature;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    public function test_custom_404_page_renders_with_adasi_branding(): void
    {
        $response = $this->get('/non-existent-page-adasi-404');
        $response->assertStatus(404)
            ->assertSee('Halaman Tidak Ditemukan')
            ->assertSee('Error 404');
    }

    public function test_custom_403_page_view_exists_and_renders(): void
    {
        $view = $this->view('errors.403', [
            'exception' => new HttpException(403, 'Akses Ditolak'),
        ]);

        $view->assertSee('Akses Ditolak')
            ->assertSee('Error 403');
    }

    public function test_custom_419_page_view_exists_and_renders(): void
    {
        $view = $this->view('errors.419');

        $view->assertSee('Sesi Telah Kedaluwarsa')
            ->assertSee('Error 419');
    }

    public function test_custom_500_page_view_exists_and_renders(): void
    {
        $view = $this->view('errors.500');

        $view->assertSee('Terjadi Kesalahan Server')
            ->assertSee('Error 500');
    }
}
