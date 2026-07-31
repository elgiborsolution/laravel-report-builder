<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Events;

use ElgiborSolution\AdvancedReports\Engine\ReportResult;
use ElgiborSolution\AdvancedReports\Models\Report;
use ElgiborSolution\AdvancedReports\Models\ReportRun;

class ReportCompleted
{
    public function __construct(
        public Report $report,
        public ReportRun $run,
        public ReportResult $result,
    ) {}
}
