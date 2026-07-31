<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Gate policy is checked in the controller.
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:100', 'unique:advanced_reports,code'],
            'description' => ['nullable', 'string', 'max:1000'],
            'data_source' => ['required', 'string', 'max:100'],
            'definition' => ['nullable', 'array'],
            'definition.name' => ['nullable', 'string'],
            'definition.data_source' => ['nullable', 'string'],
            'definition.columns' => ['nullable', 'array'],
            'definition.filters' => ['nullable', 'array'],
            'definition.groups' => ['nullable', 'array'],
            'definition.aggregates' => ['nullable', 'array'],
            'definition.sorts' => ['nullable', 'array'],
            'definition.formulas' => ['nullable', 'array'],
            'definition.drilldowns' => ['nullable', 'array'],
            'is_public' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
