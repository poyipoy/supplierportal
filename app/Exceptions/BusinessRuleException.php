<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A workflow-state or precondition refusal (wrong status, already reserved,
 * already paid, missing verified bank account). Extends RuntimeException so
 * existing callers and tests that expect one keep working.
 */
class BusinessRuleException extends RuntimeException implements BusinessRuleViolation {}
