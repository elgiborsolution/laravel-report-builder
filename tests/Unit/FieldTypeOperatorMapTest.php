<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Tests\Unit;

use ElgiborSolution\AdvancedReports\Support\FieldTypeOperatorMap;
use ElgiborSolution\AdvancedReports\Tests\TestCase;

/**
 * Unit tests for FieldTypeOperatorMap — validates the static mapping of
 * field types to compatible operators, aggregates, and formats.
 */
class FieldTypeOperatorMapTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Operator Tests
    |--------------------------------------------------------------------------
    */

    public function test_string_type_has_contains_operator(): void
    {
        $operators = FieldTypeOperatorMap::operatorsFor('string');

        $this->assertContains('contains', $operators);
        $this->assertContains('not_contains', $operators);
        $this->assertContains('starts_with', $operators);
        $this->assertContains('ends_with', $operators);
        $this->assertContains('equals', $operators);
        $this->assertContains('not_equals', $operators);
        $this->assertContains('is_null', $operators);
        $this->assertContains('is_not_null', $operators);
    }

    public function test_integer_type_has_between_operator(): void
    {
        $operators = FieldTypeOperatorMap::operatorsFor('integer');

        $this->assertContains('between', $operators);
        $this->assertContains('equals', $operators);
        $this->assertContains('not_equals', $operators);
        $this->assertContains('greater_than', $operators);
        $this->assertContains('less_than', $operators);
        $this->assertContains('greater_or_equal', $operators);
        $this->assertContains('less_or_equal', $operators);
        $this->assertContains('is_null', $operators);
        $this->assertContains('is_not_null', $operators);
    }

    public function test_date_type_has_before_after_operators(): void
    {
        $operators = FieldTypeOperatorMap::operatorsFor('date');

        $this->assertContains('before', $operators);
        $this->assertContains('after', $operators);
        $this->assertContains('between', $operators);
        $this->assertContains('equals', $operators);
        $this->assertContains('not_equals', $operators);
        $this->assertContains('is_null', $operators);
        $this->assertContains('is_not_null', $operators);

        // 'before' and 'after' should NOT be on string types.
        $stringOperators = FieldTypeOperatorMap::operatorsFor('string');
        $this->assertNotContains('before', $stringOperators);
        $this->assertNotContains('after', $stringOperators);
    }

    public function test_datetime_type_has_before_after_operators(): void
    {
        $operators = FieldTypeOperatorMap::operatorsFor('datetime');

        $this->assertContains('before', $operators);
        $this->assertContains('after', $operators);
        $this->assertContains('between', $operators);
    }

    public function test_boolean_type_has_minimal_operators(): void
    {
        $operators = FieldTypeOperatorMap::operatorsFor('boolean');

        $this->assertContains('equals', $operators);
        $this->assertContains('is_null', $operators);
        $this->assertContains('is_not_null', $operators);

        // Boolean should NOT have complex comparison operators.
        $this->assertNotContains('contains', $operators);
        $this->assertNotContains('greater_than', $operators);
        $this->assertNotContains('less_than', $operators);
        $this->assertNotContains('between', $operators);
        $this->assertNotContains('starts_with', $operators);

        // Only 3 operators.
        $this->assertCount(3, $operators);
    }

    public function test_decimal_type_has_same_numeric_operators_as_integer(): void
    {
        $intOperators = FieldTypeOperatorMap::operatorsFor('integer');
        $decOperators = FieldTypeOperatorMap::operatorsFor('decimal');

        $this->assertEquals($intOperators, $decOperators);
    }

    public function test_json_type_has_only_null_operators(): void
    {
        $operators = FieldTypeOperatorMap::operatorsFor('json');

        $this->assertContains('is_null', $operators);
        $this->assertContains('is_not_null', $operators);
        $this->assertCount(2, $operators);
    }

    public function test_unknown_type_falls_back_to_string_operators(): void
    {
        $operators = FieldTypeOperatorMap::operatorsFor('unknown_type');
        $stringOperators = FieldTypeOperatorMap::operatorsFor('string');

        $this->assertEquals($stringOperators, $operators);
    }

    /*
    |--------------------------------------------------------------------------
    | Aggregate Tests
    |--------------------------------------------------------------------------
    */

    public function test_suggested_aggregates_for_numeric_types(): void
    {
        $intAggregates = FieldTypeOperatorMap::aggregatesFor('integer');
        $this->assertSame(['count', 'sum', 'avg', 'min', 'max'], $intAggregates);

        $decAggregates = FieldTypeOperatorMap::aggregatesFor('decimal');
        $this->assertSame(['count', 'sum', 'avg', 'min', 'max'], $decAggregates);
    }

    public function test_string_aggregates_limited_to_count(): void
    {
        $aggregates = FieldTypeOperatorMap::aggregatesFor('string');

        $this->assertSame(['count'], $aggregates);
        $this->assertNotContains('sum', $aggregates);
        $this->assertNotContains('avg', $aggregates);
    }

    public function test_date_aggregates_only_include_count(): void
    {
        $aggregates = FieldTypeOperatorMap::aggregatesFor('date');

        $this->assertSame(['count'], $aggregates);
        $this->assertNotContains('sum', $aggregates);
        $this->assertNotContains('avg', $aggregates);
    }

    public function test_boolean_aggregates_only_count(): void
    {
        $aggregates = FieldTypeOperatorMap::aggregatesFor('boolean');

        $this->assertContains('count', $aggregates);
        $this->assertNotContains('sum', $aggregates);
        $this->assertNotContains('avg', $aggregates);
        $this->assertNotContains('min', $aggregates);
        $this->assertNotContains('max', $aggregates);
    }

    /*
    |--------------------------------------------------------------------------
    | Format Tests
    |--------------------------------------------------------------------------
    */

    public function test_formats_for_date_types(): void
    {
        $dateFormats = FieldTypeOperatorMap::formatsFor('date');
        $this->assertContains('date', $dateFormats);
        $this->assertContains('date_short', $dateFormats);
        $this->assertContains('date_long', $dateFormats);
        $this->assertContains('relative', $dateFormats);

        $datetimeFormats = FieldTypeOperatorMap::formatsFor('datetime');
        $this->assertContains('datetime', $datetimeFormats);
        $this->assertContains('date', $datetimeFormats);
        $this->assertContains('time', $datetimeFormats);
        $this->assertContains('relative', $datetimeFormats);
    }

    public function test_formats_for_string_types(): void
    {
        $formats = FieldTypeOperatorMap::formatsFor('string');

        $this->assertContains('string', $formats);
        $this->assertContains('uppercase', $formats);
        $this->assertContains('lowercase', $formats);
        $this->assertContains('capitalize', $formats);
    }

    public function test_formats_for_numeric_types(): void
    {
        $intFormats = FieldTypeOperatorMap::formatsFor('integer');
        $this->assertContains('integer', $intFormats);
        $this->assertContains('number', $intFormats);
        $this->assertContains('currency', $intFormats);
        $this->assertContains('percentage', $intFormats);

        $decFormats = FieldTypeOperatorMap::formatsFor('decimal');
        $this->assertContains('decimal', $decFormats);
        $this->assertContains('number', $decFormats);
        $this->assertContains('currency', $decFormats);
        $this->assertContains('percentage', $decFormats);
    }

    public function test_formats_for_boolean_types(): void
    {
        $formats = FieldTypeOperatorMap::formatsFor('boolean');

        $this->assertContains('boolean', $formats);
        $this->assertContains('yes_no', $formats);
        $this->assertContains('true_false', $formats);
        $this->assertContains('active_inactive', $formats);
    }

    public function test_unknown_type_falls_back_to_string_formats(): void
    {
        $formats = FieldTypeOperatorMap::formatsFor('nonexistent');
        $stringFormats = FieldTypeOperatorMap::formatsFor('string');

        $this->assertEquals($stringFormats, $formats);
    }

    /*
    |--------------------------------------------------------------------------
    | Static Method Tests
    |--------------------------------------------------------------------------
    */

    public function test_all_operators_returns_complete_map(): void
    {
        $all = FieldTypeOperatorMap::allOperators();

        $this->assertIsArray($all);
        $this->assertArrayHasKey('string', $all);
        $this->assertArrayHasKey('integer', $all);
        $this->assertArrayHasKey('decimal', $all);
        $this->assertArrayHasKey('date', $all);
        $this->assertArrayHasKey('datetime', $all);
        $this->assertArrayHasKey('boolean', $all);
        $this->assertArrayHasKey('json', $all);
    }

    public function test_all_aggregates_returns_complete_map(): void
    {
        $all = FieldTypeOperatorMap::allAggregates();

        $this->assertIsArray($all);
        $this->assertArrayHasKey('string', $all);
        $this->assertArrayHasKey('integer', $all);
        $this->assertArrayHasKey('decimal', $all);
        $this->assertArrayHasKey('date', $all);
        $this->assertArrayHasKey('datetime', $all);
        $this->assertArrayHasKey('boolean', $all);
        $this->assertArrayHasKey('json', $all);
    }

    public function test_all_formats_returns_complete_map(): void
    {
        $all = FieldTypeOperatorMap::allFormats();

        $this->assertIsArray($all);
        $this->assertArrayHasKey('string', $all);
        $this->assertArrayHasKey('integer', $all);
        $this->assertArrayHasKey('decimal', $all);
        $this->assertArrayHasKey('date', $all);
        $this->assertArrayHasKey('datetime', $all);
        $this->assertArrayHasKey('boolean', $all);
        $this->assertArrayHasKey('json', $all);
    }

    public function test_supported_types_returns_all_known_types(): void
    {
        $types = FieldTypeOperatorMap::supportedTypes();

        $this->assertContains('string', $types);
        $this->assertContains('integer', $types);
        $this->assertContains('decimal', $types);
        $this->assertContains('date', $types);
        $this->assertContains('datetime', $types);
        $this->assertContains('boolean', $types);
        $this->assertContains('json', $types);
        $this->assertCount(7, $types);
    }
}
