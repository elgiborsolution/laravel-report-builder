<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Thrown when a report definition fails validation. Collects all errors
 * rather than failing on the first so the frontend designer can show them all.
 */
class DefinitionInvalidException extends AdvancedReportException implements HttpExceptionInterface
{
    /** @param array<int, string> $errors */
    public function __construct(public array $errors, string $message = 'The report definition is invalid.')
    {
        parent::__construct($message.': '.implode('; ', $errors));
    }

    public function getStatusCode(): int
    {
        return 422;
    }

    public function getHeaders(): array
    {
        return [];
    }

    public function render(Request $request): JsonResponse|false
    {
        if (! $request->expectsJson()) {
            return false;
        }

        return new JsonResponse([
            'message' => $this->getMessage(),
            'errors' => $this->errors,
        ], $this->getStatusCode());
    }
}
