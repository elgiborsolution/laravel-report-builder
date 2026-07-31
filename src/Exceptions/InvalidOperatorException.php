<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Exceptions;

class InvalidOperatorException extends AdvancedReportException
{
    public static function forOperator(string $operator, array $allowed = []): self
    {
        $allowedList = $allowed ? ' Allowed: '.implode(', ', $allowed).'.' : '';

        return new self("Unsupported filter operator [{$operator}].{$allowedList}");
    }
}
