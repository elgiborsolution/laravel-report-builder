<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Http\Controllers;

use ElgiborSolution\AdvancedReports\Contracts\ExpressionEvaluator;
use ElgiborSolution\AdvancedReports\Sources\SourceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FormulaController
{
    public function __construct(
        private readonly SourceRegistry $sources,
        private readonly ExpressionEvaluator $expressions,
    ) {}

    /** Validate an expression with exactly the variables available to a source. */
    public function validate(Request $request, string $source): JsonResponse
    {
        if (! $this->sources->has($source)) {
            return response()->json(['message' => "Source [{$source}] is not registered."], 404);
        }

        $data = $request->validate([
            'expression' => ['required', 'string'],
            'formula_names' => ['sometimes', 'array'],
            'formula_names.*' => ['string'],
        ]);

        $variables = array_merge(
            $this->sources->get($source)->fields()->keys()->all(),
            $data['formula_names'] ?? [],
        );
        $errors = $this->expressions->validate($data['expression'], $variables);

        return response()->json(['valid' => empty($errors), 'errors' => $errors]);
    }
}
