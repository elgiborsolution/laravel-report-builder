<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Renderers;

use ElgiborSolution\AdvancedReports\Contracts\ReportRenderer;
use ElgiborSolution\AdvancedReports\Engine\ReportResult;
use ElgiborSolution\AdvancedReports\Support\Formatter;
use ElgiborSolution\AdvancedReports\Support\PresentationTableRows;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV renderer. Streams rows to a Symfony StreamedResponse for memory-safe
 * handling of large datasets.
 */
final class CsvRenderer implements ReportRenderer
{
    public function __construct(protected Formatter $formatter) {}

    public function format(): string
    {
        return 'csv';
    }

    public function render(ReportResult $result, array $options = []): mixed
    {
        $filename = ($options['filename'] ?? 'report').'_'.now()->format('Y-m-d_His').'.csv';
        $delimiter = $options['delimiter'] ?? ',';
        $enclosure = $options['enclosure'] ?? '"';
        $escape = $options['escape'] ?? '\\';
        $bom = (bool) ($options['bom'] ?? true);
        $layout = $result->layout ?? [];
        $headerText = trim((string) ($layout['headerText'] ?? ''));
        $footerText = trim((string) ($layout['footerText'] ?? ''));

        $columns = $result->columns;

        return new StreamedResponse(function () use ($result, $columns, $bom, $options, $delimiter, $enclosure, $escape, $headerText, $footerText) {
            $handle = fopen('php://output', 'w');
            if (! $handle) {
                return;
            }

            // BOM for Excel UTF-8 compatibility.
            if ($bom) {
                fwrite($handle, "\xEF\xBB\xBF");
            }

            // CSV has no page header/footer areas. Preserve configured content
            // as distinct lines while keeping the column header row intact.
            if ($headerText !== '') {
                fputcsv($handle, array_merge([$headerText], array_fill(0, max(0, count($columns) - 1), '')), $delimiter, $enclosure, $escape);
            }

            // Header row.
            $headers = array_map(
                fn ($col) => $col['label'] ?? ucfirst($col['field'] ?? ''),
                $columns
            );
            fputcsv($handle, $headers, $delimiter, $enclosure, $escape);

            $presentationRows = $result->presentationRows;

            if ($result->definition->groups === []) {
                // Preserve the streaming path for ungrouped reports; their
                // detail rows precede any optional grand-total event.
                foreach ($result->rows as $row) {
                    fputcsv($handle, PresentationTableRows::detailCells((array) $row, $columns, $this->formatter), $delimiter, $enclosure, $escape);
                }

                foreach ($presentationRows as $presentationRow) {
                    if (! in_array($presentationRow['type'] ?? null, ['group_subtotal', 'grand_total'], true)
                        || ! ($options['include_aggregates'] ?? true)) {
                        continue;
                    }

                    if (! PresentationTableRows::hasLabelCell($presentationRow, $columns, $result->definition->aggregates, $result->layout)) {
                        fputcsv($handle, PresentationTableRows::labelCells($presentationRow, $columns, $result->definition->aggregates, $result->layout), $delimiter, $enclosure, $escape);
                    }

                    fputcsv($handle, PresentationTableRows::cells($presentationRow, collect(), $columns, $this->formatter, $result->definition->aggregates, $result->layout), $delimiter, $enclosure, $escape);
                }
            } else {
                $details = $result->rows instanceof \Illuminate\Support\LazyCollection
                    ? $result->rows->collect()
                    : $result->rows;
                foreach ($presentationRows as $presentationRow) {
                    if (in_array($presentationRow['type'] ?? null, ['group_subtotal', 'grand_total'], true)
                        && ! ($options['include_aggregates'] ?? true)) {
                        continue;
                    }

                    if (in_array($presentationRow['type'] ?? null, ['group_subtotal', 'grand_total'], true)
                        && ! PresentationTableRows::hasLabelCell($presentationRow, $columns, $result->definition->aggregates, $result->layout)) {
                        fputcsv($handle, PresentationTableRows::labelCells($presentationRow, $columns, $result->definition->aggregates, $result->layout), $delimiter, $enclosure, $escape);
                    }

                    $cells = PresentationTableRows::cells($presentationRow, $details, $columns, $this->formatter, $result->definition->aggregates, $result->layout);
                    fputcsv($handle, $cells, $delimiter, $enclosure, $escape);
                }
            }

            if ($footerText !== '') {
                fputcsv($handle, array_merge([$footerText], array_fill(0, max(0, count($columns) - 1), '')), $delimiter, $enclosure, $escape);
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
