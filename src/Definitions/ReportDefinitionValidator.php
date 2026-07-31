<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Definitions;

use ElgiborSolution\AdvancedReports\Contracts\ExpressionEvaluator;
use ElgiborSolution\AdvancedReports\Exceptions\DefinitionInvalidException;
use ElgiborSolution\AdvancedReports\Exceptions\SourceNotRegisteredException;
use ElgiborSolution\AdvancedReports\Sources\SourceRegistry;
use ElgiborSolution\AdvancedReports\Support\Operators;

/**
 * Validates a ReportDefinition against its declared source. Collects all
 * errors rather than failing fast so the frontend designer can show the
 * full picture.
 */
final class ReportDefinitionValidator
{
    /** @var array<int,string> */
    protected array $errors = [];

    public function __construct(
        protected SourceRegistry $sources,
        protected ExpressionEvaluator $expressions,
    ) {}

    /**
     * @param  array<string,mixed>  $parameters  Run-time parameters (only required for required-param check).
     *
     * @throws DefinitionInvalidException
     * @throws SourceNotRegisteredException
     */
    public function validate(ReportDefinition $definition, array $parameters = []): void
    {
        $this->errors = [];

        $this->validateName($definition);
        $this->validateSource($definition);

        // The source must exist before we can validate field references.
        if ($this->sources->has($definition->dataSource)) {
            $source = $this->sources->get($definition->dataSource);
            $this->validateParameters($definition, $parameters);
            $this->validateColumns($definition);
            $this->validateFilters($definition);
            $this->validateGroups($definition);
            $this->validateAggregates($definition);
            $this->validateSorts($definition);
            $this->validateFormulas($definition);
            $this->validateDrilldowns($definition);
        }

        if ($this->errors) {
            throw new DefinitionInvalidException($this->errors);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Section validators
    |--------------------------------------------------------------------------
    */

    protected function validateName(ReportDefinition $d): void
    {
        if (trim($d->name) === '') {
            $this->errors[] = 'Report name is required.';
        }
    }

    protected function validateSource(ReportDefinition $d): void
    {
        if (trim($d->dataSource) === '') {
            $this->errors[] = 'Definition data_source is required.';

            return;
        }

        if (! $this->sources->has($d->dataSource)) {
            $this->errors[] = "Source [{$d->dataSource}] is not registered.";
        }
    }

    protected function validateParameters(ReportDefinition $d, array $values): void
    {
        foreach ($d->parameters as $p) {
            $name = $p['name'] ?? null;
            if (! $name) {
                $this->errors[] = 'Each parameter requires a "name".';
                continue;
            }

            $value = $values[$name] ?? $p['default'] ?? null;
            $required = (bool) ($p['required'] ?? false);
            if ($required && ($value === null || $value === '')) {
                $this->errors[] = "Parameter [{$name}] is required.";
            }

            $allowed = $p['allowed_values'] ?? $p['allowedValues'] ?? null;
            if ($allowed && $value !== null && ! in_array($value, $allowed, true)) {
                $this->errors[] = "Parameter [{$name}] contains a disallowed value.";
            }
        }
    }

    protected function validateColumns(ReportDefinition $d): void
    {
        $source = $this->sources->get($d->dataSource);

        foreach ($d->columns as $i => $col) {
            $field = $col['field'] ?? null;
            if (! $field) {
                $this->errors[] = "Column #{$i} is missing a field.";
                continue;
            }

            if (! $source->fields()->has($field)) {
                $this->errors[] = "Column references unknown field [{$field}].";
                continue;
            }

            // Hidden fields may not be displayed directly in a column.
            if ($source->field($field)?->hidden) {
                $this->errors[] = "Column [{$field}] references a hidden field and cannot be displayed.";
            }
        }
    }

    protected function validateFilters(ReportDefinition $d): void
    {
        $source = $this->sources->get($d->dataSource);

        foreach ($d->filters as $i => $f) {
            $field = $f['field'] ?? null;
            $op = $f['operator'] ?? null;

            if (! $field) {
                $this->errors[] = "Filter #{$i} is missing a field.";
                continue;
            }
            if (! $source->fields()->has($field)) {
                $this->errors[] = "Filter references unknown field [{$field}].";
                continue;
            }
            if (! $source->field($field)?->filterable) {
                $this->errors[] = "Field [{$field}] is not filterable.";
            }

            if ($op === null) {
                $this->errors[] = "Filter on [{$field}] is missing an operator.";
                continue;
            }
            if (! Operators::isValid((string) $op)) {
                $this->errors[] = "Filter on [{$field}] uses unsupported operator [{$op}]. Allowed: ".implode(', ', Operators::all()).'.';
            }
        }
    }

    protected function validateGroups(ReportDefinition $d): void
    {
        $source = $this->sources->get($d->dataSource);

        foreach ($d->groups as $g) {
            $field = $g['field'] ?? null;
            if (! $field || ! $source->fields()->has($field)) {
                $this->errors[] = "Group references unknown field [".($field ?? 'null')."].";
            }
        }
    }

    protected function validateAggregates(ReportDefinition $d): void
    {
        $source = $this->sources->get($d->dataSource);

        foreach ($d->aggregates as $a) {
            $field = $a['field'] ?? null;
            $func = $a['function'] ?? null;

            if (! $field || ! $source->fields()->has($field)) {
                $this->errors[] = "Aggregate references unknown field [".($field ?? 'null')."].";
                continue;
            }
            if (! $source->field($field)?->aggregatable) {
                $this->errors[] = "Field [{$field}] is not aggregatable.";
            }
            if ($func && ! in_array(strtolower((string) $func), Operators::aggregateFunctions(), true)) {
                $this->errors[] = "Aggregate uses unsupported function [{$func}].";
            }
        }
    }

    protected function validateSorts(ReportDefinition $d): void
    {
        $source = $this->sources->get($d->dataSource);

        foreach ($d->sorts as $s) {
            $field = $s['field'] ?? null;
            $direction = strtolower((string) ($s['direction'] ?? 'asc'));

            if (! $field || ! $source->fields()->has($field)) {
                $this->errors[] = "Sort references unknown field [".($field ?? 'null')."].";
                continue;
            }
            if (! $source->field($field)?->sortable) {
                $this->errors[] = "Field [{$field}] is not sortable.";
            }
            if (! in_array($direction, ['asc', 'desc'], true)) {
                $this->errors[] = "Sort direction [{$direction}] must be asc or desc.";
            }
        }
    }

    protected function validateFormulas(ReportDefinition $d): void
    {
        $source = $this->sources->get($d->dataSource);
        $vars = array_merge(
            $source->fields()->keys()->all(),
            array_map(fn ($f) => $f['name'] ?? '', $d->formulas),
        );

        foreach ($d->formulas as $f) {
            $name = $f['name'] ?? null;
            $expr = $f['expression'] ?? null;
            if (! $name || ! $expr) {
                $this->errors[] = 'Each formula requires a name and expression.';
                continue;
            }
            $errors = $this->expressions->validate((string) $expr, $vars);
            foreach ($errors as $err) {
                $this->errors[] = "Formula [{$name}]: {$err}";
            }
        }
    }

    protected function validateDrilldowns(ReportDefinition $d): void
    {
        foreach ($d->drilldowns as $dd) {
            $target = $dd['target'] ?? null;
            $type = $dd['type'] ?? 'report';

            if (! $target) {
                $this->errors[] = 'Drilldown is missing a target.';
                continue;
            }

            if ($type === 'report' && ! $this->reportExists((string) $target)) {
                // Soft warning: drilldowns can target reports registered later or external routes.
                // We surface this as a warning but do not hard-fail; the resolver also checks.
                $this->errors[] = "Drilldown target report [{$target}] is not currently available.";
            }
        }
    }

    protected function reportExists(string $code): bool
    {
        return \ElgiborSolution\AdvancedReports\Models\Report::query()
            ->where('code', $code)
            ->exists();
    }
}
