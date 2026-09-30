<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Support;

/**
 * Static mapping of field types to their compatible operators,
 * suggested aggregates, and available display formats.
 *
 * Used by the designer schema to provide contextual guidance to
 * the frontend report builder UI.
 */
final class FieldTypeOperatorMap
{
    /** @var array<string, array<int, string>> */
    private const OPERATORS = [
        'string' => [
            'equals',
            'not_equals',
            'contains',
            'not_contains',
            'starts_with',
            'ends_with',
            'is_null',
            'is_not_null',
        ],
        'integer' => [
            'equals',
            'not_equals',
            'greater_than',
            'less_than',
            'greater_or_equal',
            'less_or_equal',
            'between',
            'is_null',
            'is_not_null',
        ],
        'decimal' => [
            'equals',
            'not_equals',
            'greater_than',
            'less_than',
            'greater_or_equal',
            'less_or_equal',
            'between',
            'is_null',
            'is_not_null',
        ],
        'date' => [
            'equals',
            'not_equals',
            'before',
            'after',
            'between',
            'is_null',
            'is_not_null',
        ],
        'datetime' => [
            'equals',
            'not_equals',
            'before',
            'after',
            'between',
            'is_null',
            'is_not_null',
        ],
        'boolean' => [
            'equals',
            'is_null',
            'is_not_null',
        ],
        'json' => [
            'is_null',
            'is_not_null',
        ],
    ];

    /** @var array<string, array<int, string>> */
    private const AGGREGATES = [
        'string' => ['count'],
        'integer' => ['count', 'sum', 'avg', 'min', 'max'],
        'decimal' => ['count', 'sum', 'avg', 'min', 'max'],
        'date' => ['count'],
        'datetime' => ['count'],
        'boolean' => ['count'],
        'json' => ['count'],
    ];

    /** @var array<string, array<int, string>> */
    private const FORMATS = [
        'string' => ['string', 'uppercase', 'lowercase', 'capitalize'],
        'integer' => ['integer', 'number', 'currency', 'percentage'],
        'decimal' => ['decimal', 'number', 'currency', 'percentage'],
        'date' => ['date', 'date_short', 'date_long', 'relative'],
        'datetime' => ['datetime', 'date', 'time', 'relative'],
        'boolean' => ['boolean', 'yes_no', 'true_false', 'active_inactive'],
        'json' => ['json', 'string'],
    ];

    /**
     * Get compatible operators for a field type.
     *
     * @return array<int, string>
     */
    public static function operatorsFor(string $type): array
    {
        return self::OPERATORS[$type] ?? self::OPERATORS['string'];
    }

    /**
     * Get suggested aggregate functions for a field type.
     *
     * @return array<int, string>
     */
    public static function aggregatesFor(string $type): array
    {
        return self::AGGREGATES[$type] ?? self::AGGREGATES['string'];
    }

    /**
     * Get available display formats for a field type.
     *
     * @return array<int, string>
     */
    public static function formatsFor(string $type): array
    {
        return self::FORMATS[$type] ?? self::FORMATS['string'];
    }

    /**
     * Get the complete map of all operators by type.
     *
     * @return array<string, array<int, string>>
     */
    public static function allOperators(): array
    {
        return self::OPERATORS;
    }

    /**
     * Get the complete map of all aggregates by type.
     *
     * @return array<string, array<int, string>>
     */
    public static function allAggregates(): array
    {
        return self::AGGREGATES;
    }

    /**
     * Get the complete map of all formats by type.
     *
     * @return array<string, array<int, string>>
     */
    public static function allFormats(): array
    {
        return self::FORMATS;
    }

    /**
     * Get all supported field types.
     *
     * @return array<int, string>
     */
    public static function supportedTypes(): array
    {
        return array_keys(self::OPERATORS);
    }
}
