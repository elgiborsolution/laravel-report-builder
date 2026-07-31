<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Support;

/**
 * Centralized, immutable catalog of supported filter operators and
 * aggregate functions. Kept separate from resolvers for testability.
 */
final class Operators
{
    /** @var array<string,string> */
    public const MAPPING = [
        '=' => '=',
        '==' => '=',
        '!=' => '!=',
        '<>' => '!=',
        '<' => '<',
        '<=' => '<=',
        '>' => '>',
        '>=' => '>=',
        'like' => 'LIKE',
        'not like' => 'NOT LIKE',
        'ilike' => 'ILIKE',
        'in' => 'In',
        'not in' => 'NotIn',
        'between' => 'Between',
        'not between' => 'NotBetween',
        'is null' => 'Null',
        'is not null' => 'NotNull',
        'date' => 'Date',
        'month' => 'Month',
        'year' => 'Year',
    ];

    /** @var array<int,string> */
    public const FUNCTIONS = ['sum', 'avg', 'min', 'max', 'count'];

    public static function isValid(string $operator): bool
    {
        return array_key_exists(strtolower($operator), array_change_key_case(self::MAPPING, CASE_LOWER));
    }

    public static function normalize(string $operator): string
    {
        return strtolower($operator);
    }

    /** @return array<int,string> */
    public static function all(): array
    {
        return array_keys(self::MAPPING);
    }

    /** @return array<int,string> */
    public static function aggregateFunctions(): array
    {
        return self::FUNCTIONS;
    }
}
