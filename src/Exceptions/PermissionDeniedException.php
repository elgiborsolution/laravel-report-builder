<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Exceptions;

use ElgiborSolution\AdvancedReports\Models\Report;

class PermissionDeniedException extends AdvancedReportException
{
    public function __construct(public string $ability, public Report $report)
    {
        parent::__construct("User is not authorized to [{$ability}] report [{$report->code}].");
    }
}
