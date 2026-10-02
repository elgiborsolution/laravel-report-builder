<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Definitions;

use ElgiborSolution\AdvancedReports\Contracts\ExpressionEvaluator;
use ElgiborSolution\AdvancedReports\Contracts\ReportSourceContract;
use ElgiborSolution\AdvancedReports\Exceptions\DefinitionInvalidException;
use ElgiborSolution\AdvancedReports\Exceptions\SourceNotRegisteredException;
use ElgiborSolution\AdvancedReports\Sources\SourceRegistry;
use ElgiborSolution\AdvancedReports\Support\Operators;
use ElgiborSolution\AdvancedReports\Support\SummaryRowLayout;
use ElgiborSolution\AdvancedReports\Support\ValueResolver;
use Illuminate\Support\Facades\Validator;

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
            $this->validateParameters($source, $definition, $parameters);
            $this->validateColumns($definition);
            $this->validateFilters($definition);
            $this->validateGroups($definition);
            $this->validateAggregates($definition);
            $this->validateSorts($definition);
            $this->validateFormulas($definition);
            $this->validateDrilldowns($definition);
        }

        foreach (SummaryRowLayout::validationErrors($definition->layout, $definition->aggregates) as $error) {
            $this->errors[] = $error;
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

    protected function validateParameters(ReportSourceContract $source, ReportDefinition $d, array $values): void
    {
        $declarations = [];

        foreach ($source->parameters() as $parameter) {
            $data = $parameter->toArray();
            $name = (string) ($data['name'] ?? '');
            if ($name !== '') {
                $declarations[$name] = $data;
            }
        }

        foreach ($d->parameters as $p) {
            $name = $p['name'] ?? null;
            if (! $name) {
                $this->errors[] = 'Each parameter requires a "name".';
                continue;
            }

            if (array_key_exists($name, $declarations)) {
                // Source metadata owns type/required/options; report
                // definitions can persist only their default binding.
                if (array_key_exists('default', $p)) {
                    $declarations[$name]['default'] = $p['default'];
                }
                continue;
            }

            $declarations[$name] = $p;
        }

        $resolver = new ValueResolver($values);
        foreach ($declarations as $name => $parameter) {
            $value = array_key_exists($name, $values)
                ? $values[$name]
                : ($parameter['default'] ?? null);
            $value = $resolver->resolve($value);
            $required = (bool) ($parameter['required'] ?? false);
            if ($required && ($value === null || $value === '')) {
                $this->errors[] = "Parameter [{$name}] is required.";
            }

            if ($value !== null && $value !== '') {
                $type = $parameter['type'] ?? 'string';
                $validType = match ($type) {
                    'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false,
                    'decimal' => is_scalar($value) && is_numeric($value),
                    'boolean' => is_scalar($value) && filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== null,
                    'date', 'datetime' => Validator::make(['value' => $value], ['value' => 'date'])->passes(),
                    'array' => is_array($value),
                    'string' => is_scalar($value),
                    default => true,
                };
                if (! $validType) {
                    $this->errors[] = "Parameter [{$name}] must be of type [{$type}].";
                }
            }

            $allowed = $parameter['allowed_values'] ?? $parameter['allowedValues'] ?? null;
            if ($allowed && $value !== null && ! in_array($value, $allowed, true)) {
                $this->errors[] = "Parameter [{$name}] contains a disallowed value.";
            }
        }
    }

    protected function validateColumns(ReportDefinition $d): void
    {
        $source = $this->sources->get($d->dataSource);
        $formulaNames = array_fill_keys(array_filter(array_map(
            fn (array $formula) => $formula['name'] ?? null,
            $d->formulas
        )), true);

        foreach ($d->columns as $i => $col) {
            $field = $col['field'] ?? null;
            if (! $field) {
                $this->errors[] = "Column #{$i} is missing a field.";
                continue;
            }

            if (! $source->fields()->has($field) && ! isset($formulaNames[$field])) {
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
            $function = strtolower((string) $func);
            if (! in_array($function, Operators::aggregateFunctions(), true)) {
                $this->errors[] = "Aggregate uses unsupported function [{$func}].";
                continue;
            }

            $reportField = $source->field($field);
            if ($function !== 'count' && (! $reportField?->aggregatable || ! in_array($reportField->type, ['integer', 'decimal'], true))) {
                $this->errors[] = "Function [{$function}] requires an aggregatable numeric field; [{$field}] is not eligible.";
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
        $seenNames = [];
        $sourceNames = [];
        foreach ($source->fields()->keys() as $fieldKey) {
            $sourceNames[] = strtolower((string) $fieldKey);
            $sourceNames[] = strtolower(str_replace('.', '_', (string) $fieldKey));
        }
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

            $normalizedName = strtolower((string) $name);
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $name)) {
                $this->errors[] = "Formula name [{$name}] must be a valid field identifier.";
            }
            if (isset($seenNames[$normalizedName])) {
                $this->errors[] = "Formula name [{$name}] is duplicated.";
            }
            if (in_array($normalizedName, $sourceNames, true)) {
                $this->errors[] = "Formula name [{$name}] conflicts with a source field.";
            }
            $seenNames[$normalizedName] = true;

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
