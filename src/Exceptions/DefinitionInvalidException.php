<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Exceptions;

/**
 * Thrown when a report definition fails validation. Collects all errors
 * rather than failing on the first so the frontend designer can show them all.
 */
class DefinitionInvalidException extends AdvancedReportException
{
    /** @param array<int, string> $errors */
    public function __construct(public array $errors, string $message = 'The report definition is invalid.')
    {
        parent::__construct($message.': '.implode('; ', $errors));
    }
}
