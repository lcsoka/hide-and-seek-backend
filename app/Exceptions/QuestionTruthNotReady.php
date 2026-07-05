<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by ComputeQuestionTruth when Overpass is momentarily unavailable so the queued job retries
 * with backoff. It's an expected transient condition (the Overpass failures are already logged as
 * warnings), so it's excluded from error reporting — see bootstrap/app.php `dontReport`.
 */
class QuestionTruthNotReady extends RuntimeException {}
