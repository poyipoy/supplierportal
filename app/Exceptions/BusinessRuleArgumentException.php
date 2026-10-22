<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * A refusal of the submitted choice (missing reason, empty selection, role not
 * allowed for this step). Extends InvalidArgumentException so existing callers
 * and tests that expect one keep working.
 */
class BusinessRuleArgumentException extends InvalidArgumentException implements BusinessRuleViolation {}
