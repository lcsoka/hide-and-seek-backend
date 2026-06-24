<?php

namespace App\Game\Questions;

use App\Enums\QuestionCategory;

class QuestionEvaluatorRegistry
{
    /** @var array<string, QuestionEvaluator> */
    private array $byCategory = [];

    public function __construct()
    {
        foreach (config('game.question_evaluators', []) as $class) {
            $evaluator = app($class);
            $this->byCategory[$evaluator->category()->value] = $evaluator;
        }
    }

    public function for(QuestionCategory $category): ?QuestionEvaluator
    {
        return $this->byCategory[$category->value] ?? null;
    }
}
