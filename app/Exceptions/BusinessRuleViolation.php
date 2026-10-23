<?php

namespace App\Exceptions;

use Throwable;

/**
 * A refusal the actor can act on: a business rule or workflow state rejected
 * the request. The message is translated and safe to show, so the HTTP layer
 * renders it as feedback (bootstrap/app.php) instead of a server error.
 *
 * Unexpected failures (database, filesystem, programming errors) must never
 * implement this; they keep the generic error page and are reported.
 */
interface BusinessRuleViolation extends Throwable {}
