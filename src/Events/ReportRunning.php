<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Events;

use ElgiborSolution\AdvancedReports\Models\Report;
use ElgiborSolution\AdvancedReports\Models\ReportRun;

class ReportRunning
{
    public function __construct(
        public Report $report,
        public ReportRun $run,
        public array $parameters,
    ) {}
}
