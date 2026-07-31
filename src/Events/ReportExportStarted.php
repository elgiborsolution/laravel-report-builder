<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Events;

use ElgiborSolution\AdvancedReports\Models\Report;
use ElgiborSolution\AdvancedReports\Models\ReportExport;

class ReportExportStarted
{
    public function __construct(
        public Report $report,
        public ReportExport $export,
    ) {}
}
