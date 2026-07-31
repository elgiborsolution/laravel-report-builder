<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RunReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'parameters' => ['nullable', 'array'],
            'parameters.*' => ['nullable'],
            'format' => ['nullable', 'string', 'in:html,json,pdf,xlsx,csv'],
        ];
    }
}
