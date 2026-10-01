<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Http\Requests;

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
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'data_source' => ['sometimes', 'string', 'max:100'],
            'definition' => ['nullable', 'array'],
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
            // No nested rules: Laravel would otherwise drop unlisted layout keys
            // from validated(). Summary settings are checked in withValidator().
            'definition.layout' => ['nullable', 'array'],
            'is_public' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $definition = (array) $this->input('definition', []);
            foreach (SummaryRowLayout::validationErrors($definition['layout'] ?? null, $definition['aggregates'] ?? null) as $path => $message) {
                $validator->errors()->add("definition.{$path}", $message);
            }

            $sourceKey = $definition['data_source'] ?? $this->input('data_source');
            if (! $sourceKey || ! app(SourceRegistry::class)->has($sourceKey)) {
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
