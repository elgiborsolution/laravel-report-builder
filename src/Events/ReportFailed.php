<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Events;

use ElgiborSolution\AdvancedReports\Models\Report;
use ElgiborSolution\AdvancedReports\Models\ReportRun;

class ReportFailed
{
    public function __construct(
        public Report $report,
        public ReportRun $run,
        public \Throwable $exception,
    ) {}
}
