<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExportReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'format' => ['required', 'string', 'in:pdf,xlsx,csv,json'],
            'parameters' => ['nullable', 'array'],
            'parameters.*' => ['nullable'],
            'queued' => ['nullable', 'boolean'],
            'filename' => ['nullable', 'string', 'max:255'],
            'paper' => ['nullable', 'string', 'in:a4,a3,letter,legal'],
            'orientation' => ['nullable', 'string', 'in:portrait,landscape'],
        ];
    }
}
