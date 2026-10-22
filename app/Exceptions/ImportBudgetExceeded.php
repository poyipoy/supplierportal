<?php

namespace App\Exceptions;

/**
 * The whole-file confirm transaction ran past its time budget and was rolled
 * back. Retrying repeats the same work under the same locks, so the job fails
 * once instead of being released back to the queue.
 */
final class ImportBudgetExceeded extends \RuntimeException {}
