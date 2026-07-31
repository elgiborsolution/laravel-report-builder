<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Contracts;

use ElgiborSolution\AdvancedReports\Engine\ReportResult;
use Symfony\Component\HttpFoundation\Response;

/**
 * Produces a downloadable/storable file representation of a ReportResult.
 * Differs from ReportRenderer in that exporters always emit a file/Response.
 */
interface ReportExporter
{
    /**
     * The format key this exporter handles (e.g. 'pdf', 'xlsx', 'csv').
     */
    public function format(): string;

    /**
     * Synchronous export — returns a Response or writes to disk and returns the path.
     *
     * @return Response|string Either an HTTP Response or a stored file path.
     */
    public function export(ReportResult $result, array $options = []): Response|string;
}
