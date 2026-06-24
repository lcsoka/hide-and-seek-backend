<?php

namespace App\Game\Questions;

/**
 * Marks an evaluator whose answer depends on what happens *after* the question is
 * asked (e.g. thermometer — the seeker travels first). The engine captures the
 * ask-time context and runs the evaluator when the question is resolved.
 */
interface DeferredQuestionEvaluator extends QuestionEvaluator {}
