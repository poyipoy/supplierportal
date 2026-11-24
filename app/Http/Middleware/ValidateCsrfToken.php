<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken as Middleware;

class ValidateCsrfToken extends Middleware
{
    /**
     * Never emit a non-HttpOnly XSRF-TOKEN cookie; all portal requests send
     * CSRF tokens via form _token fields or X-CSRF-TOKEN from <meta>.
     *
     * @var bool
     */
    protected $addHttpCookie = false;
}
