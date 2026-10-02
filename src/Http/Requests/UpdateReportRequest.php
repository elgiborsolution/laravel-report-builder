<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Http\Requests;

use ElgiborSolution\AdvancedReports\Bridge\DataSourceBridge;
use ElgiborSolution\AdvancedReports\Definitions\ReportDefinition;
use ElgiborSolution\AdvancedReports\Models\Report;
use ElgiborSolution\AdvancedReports\Sources\SourceRegistry;
use ElgiborSolution\AdvancedReports\Support\SummaryRowLayout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'data_source' => ['sometimes', 'required', 'string', 'max:100'],
            'definition' => ['sometimes', 'array'],
            'definition.name' => ['sometimes', 'required', 'string', 'max:255'],
            'definition.description' => ['nullable', 'string'],
            'definition.data_source' => ['sometimes', 'required', 'string', 'max:100'],
            'definition.parameters' => ['nullable', 'array'],
            'definition.columns' => ['nullable', 'array'],
            'definition.filters' => ['nullable', 'array'],
            'definition.groups' => ['nullable', 'array'],
            'definition.aggregates' => ['nullable', 'array'],
            'definition.sorts' => ['nullable', 'array'],
            'definition.formulas' => ['nullable', 'array'],
            'definition.formulas.*.id' => ['nullable', 'string', 'max:100'],
            'definition.formulas.*.name' => ['required', 'string', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/', 'distinct:ignore_case'],
            'definition.formulas.*.expression' => ['required', 'string'],
            'definition.formulas.*.label' => ['nullable', 'string'],
            'definition.formulas.*.type' => ['nullable', 'string'],
            'definition.formulas.*.format' => ['nullable', 'string'],
            'definition.drilldowns' => ['nullable', 'array'],
            'definition.subreports' => ['nullable', 'array'],
            'definition.conditional_formatting' => ['nullable', 'array'],
            'definition.meta' => ['nullable', 'array'],
            // No nested rules: Laravel would otherwise drop unlisted layout keys
            // from validated(). Summary settings are checked in withValidator().
            'definition.layout' => ['nullable', 'array'],
            'is_public' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /** Merge a definition patch by section; supplied lists replace old lists. */
    public function reportAttributes(Report $report): array
    {
        $data = $this->validated();
        $definition = array_replace((array) $report->definition, (array) ($data['definition'] ?? []));
        $sourceKey = ReportDefinition::canonicalSourceKey((string) (
            $data['data_source'] ?? $data['definition']['data_source'] ?? $report->data_source
        ));
        $name = $data['name'] ?? $data['definition']['name'] ?? $report->name;

        return [
            ...$data,
            'name' => $name,
            'data_source' => $sourceKey,
            'definition' => [...$definition, 'name' => $name, 'data_source' => $sourceKey],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $definition = (array) $this->input('definition', []);
            foreach (SummaryRowLayout::validationErrors($definition['layout'] ?? null, $definition['aggregates'] ?? null) as $path => $message) {
                $validator->errors()->add("definition.{$path}", $message);
            }

            if ($validator->errors()->has('definition') || $validator->errors()->has('data_source') || $validator->errors()->has('definition.data_source')) {
                return;
            }

            $report = $this->route('report');
            $sourceKey = ReportDefinition::canonicalSourceKey((string) (
                $definition['data_source'] ?? $this->input('data_source') ?? ($report instanceof Report ? $report->data_source : '')
            ));
            if (array_key_exists('data_source', $definition) && $this->exists('data_source')
                && $sourceKey !== ReportDefinition::canonicalSourceKey((string) $this->input('data_source'))) {
                $validator->errors()->add('definition.data_source', 'The report and definition must reference the same source.');
                return;
            }
            if ($sourceKey === '' || (! app(SourceRegistry::class)->has($sourceKey) && ! app(DataSourceBridge::class)->ensureRegistered($sourceKey))) {
                $path = array_key_exists('data_source', $definition) ? 'definition.data_source' : 'data_source';
                $validator->errors()->add($path, "Report source [{$sourceKey}] is not registered.");
                return;
            }

            $sourceNames = [];
            foreach (app(SourceRegistry::class)->get($sourceKey)->fields()->keys() as $fieldKey) {
                $sourceNames[] = strtolower((string) $fieldKey);
                $sourceNames[] = strtolower(str_replace('.', '_', (string) $fieldKey));
            }

            foreach ((array) ($definition['formulas'] ?? []) as $index => $formula) {
                if (! is_array($formula)) {
                    continue;
                }

                $name = strtolower((string) ($formula['name'] ?? ''));
                if ($name !== '' && in_array($name, $sourceNames, true)) {
                    $validator->errors()->add("definition.formulas.{$index}.name", 'Formula name conflicts with a source field.');
                }
            }
        });
    }
}
