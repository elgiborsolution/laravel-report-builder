<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Contracts;

/**
 * Safely evaluates formula expressions. Must NEVER use raw eval().
 * The bundled implementation uses Symfony ExpressionLanguage with a
 * restricted set of variables and functions.
 */
interface ExpressionEvaluator
{
    /**
     * Evaluate an expression against a row of data.
     *
     * @param  string  $expression e.g. "total_amount * 0.11"
     * @param  array<string,mixed>  $context Field values keyed by name (dot-notation collapsed to underscores).
     */
    public function evaluate(string $expression, array $context): mixed;

    /**
     * Validate that an expression is syntactically valid and uses only
     * known variables/functions. Returns collected errors (empty = valid).
     *
     * @param  string  $expression
     * @param  array<int,string>  $knownVariables
     * @return array<int,string>  List of errors.
     */
    public function validate(string $expression, array $knownVariables): array;
}
