<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'definition.drilldowns' => ['nullable', 'array'],
            'is_public' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
