<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Support;

/** Formula-language metadata shared by the evaluator and visual designer. */
final class FormulaCapabilities
{
    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'field_reference' => 'identifier',
            'field_key_normalization' => 'Dots in source field keys are replaced with underscores.',
            'operators' => [
                'arithmetic' => ['+', '-', '*', '/', '%'],
                'comparison' => ['==', '!=', '<', '<=', '>', '>='],
                'logical' => ['and', 'or', 'not'],
                'null' => ['??'],
                'conditional' => ['condition ? value_if_true : value_if_false'],
            ],
            'functions' => array_keys(self::functions()),
            'evaluation_scope' => 'row',
        ];
    }

    /** @return array<string, callable> */
    public static function functions(): array
    {
        return [
            'now' => fn () => \Carbon\Carbon::now(),
            'date' => fn ($v) => \Carbon\Carbon::parse($v),
            'round' => fn (...$args) => round(...$args),
            'abs' => fn ($v) => abs($v),
            'int' => fn ($v) => (int) $v,
            'float' => fn ($v) => (float) $v,
            'string' => fn ($v) => (string) $v,
            'sum' => fn (...$args) => array_sum($args),
            'avg' => fn (...$args) => count($args) ? array_sum($args) / count($args) : 0,
            'min' => fn (...$args) => min($args),
            'max' => fn (...$args) => max($args),
        ];
    }

    /** @return array<int, string> */
    public static function functionNames(): array
    {
        return array_keys(self::functions());
    }
}
