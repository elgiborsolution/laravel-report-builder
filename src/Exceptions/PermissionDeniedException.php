<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Exceptions;

use ElgiborSolution\AdvancedReports\Models\Report;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PermissionDeniedException extends AdvancedReportException implements HttpExceptionInterface
{
    public function __construct(public string $ability, public Report $report)
    {
        parent::__construct("User is not authorized to [{$ability}] report [{$report->code}].");
    }

    public function getStatusCode(): int
    {
        return 403;
    }

    public function getHeaders(): array
    {
        return [];
    }
}
