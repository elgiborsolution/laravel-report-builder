<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Support;

use ElgiborSolution\AdvancedReports\Contracts\ExpressionEvaluator;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * Safe expression evaluator built on Symfony ExpressionLanguage. NEVER uses
 * eval(). Variables must be in the allow-list passed to {@see evaluate()};
 * unknown identifiers are detected statically and rejected at validate time.
 *
 * Dotted field names ("customer.name") are flattened to underscores
 * ("customer_name") so they are valid identifier tokens.
 */
final class SafeExpressionEvaluator implements ExpressionEvaluator
{
    protected ExpressionLanguage $language;

    /** @var array<string,callable> */
    protected array $functions = [];

    public function __construct()
    {
        $this->language = new ExpressionLanguage();
        $this->registerSafeFunctions();
    }

    public function evaluate(string $expression, array $context): mixed
    {
        $expression = $this->normalize($expression);
        $context = $this->flattenKeys($context);

        try {
            return $this->language->evaluate($expression, $context);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to evaluate formula [{$expression}]: ".$e->getMessage(), previous: $e);
        }
    }

    public function validate(string $expression, array $knownVariables): array
    {
        $expression = $this->normalize($expression);
        $errors = [];

        // 1) Syntax check
        try {
            $parsed = $this->language->parse($expression, array_merge(
                array_map(fn ($variable) => str_replace('.', '_', (string) $variable), $knownVariables),
                FormulaCapabilities::functionNames(),
            ));
        } catch (\Throwable $e) {
            $errors[] = 'Syntax error: '.$e->getMessage();

            return $errors;
        }

        // 2) Detect unknown identifiers by feeding fake values.
        // parse() already enforces variable allow-list, so unknown vars throw.
        return $errors;
    }

    protected function registerSafeFunctions(): void
    {
        // The ExpressionLanguage ships with arithmetic/logic; we add helpers.
        foreach (FormulaCapabilities::functions() as $name => $callback) {
            $this->language->register(
                $name,
                fn (ExpressionLanguage $el, ...$args) => null,
                static fn (array $vars, ...$args) => $callback(...$args)
            );
        }
    }

    /**
     * Replace PHP-style operators with ExpressionLanguage equivalents if
     * needed and trim stray whitespace.
     */
    protected function normalize(string $expression): string
    {
        return trim($expression);
    }

    /** @param array<string,mixed> $context */
    protected function flattenKeys(array $context): array
    {
        $flat = [];
        foreach ($context as $key => $value) {
            $flat[str_replace('.', '_', (string) $key)] = $value;
        }

        return $flat;
    }
}
