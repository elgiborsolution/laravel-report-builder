<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Contracts;

use ElgiborSolution\AdvancedReports\Engine\ReportResult;

/**
 * Transforms a ReportResult into a rendered representation.
 * Implementations: HtmlRenderer, PdfRenderer, ExcelRenderer, CsvRenderer, JsonRenderer.
 */
interface ReportRenderer
{
    /**
     * The format key this renderer handles (e.g. 'html', 'pdf').
     */
    public function format(): string;

    /**
     * Render the result. May return a string (HTML/CSV), array (JSON),
     * or Symfony Response (file downloads).
     */
    public function render(ReportResult $result, array $options = []): mixed;
}
