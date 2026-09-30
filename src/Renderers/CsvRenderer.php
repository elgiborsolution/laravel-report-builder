<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Renderers;

use ElgiborSolution\AdvancedReports\Contracts\ReportRenderer;
use ElgiborSolution\AdvancedReports\Engine\ReportResult;
use ElgiborSolution\AdvancedReports\Support\Formatter;
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

        // Callback writes to php://output (streamed).
        $writeRow = function (array $row) use ($delimiter, $enclosure, $escape) {
            $fp = fopen('php://output', 'w');
            fputcsv($fp, $row, $delimiter, $enclosure, $escape);
            fclose($fp);
        };

        $columns = $result->columns;

        return new StreamedResponse(function () use ($result, $columns, $writeRow, $bom, $options) {
            $handle = fopen('php://output', 'w');
            if (! $handle) {
                return;
            }

            // BOM for Excel UTF-8 compatibility.
            if ($bom) {
                fwrite($handle, "\xEF\xBB\xBF");
            }

            // Header row.
            $headers = array_map(
                fn ($col) => $col['label'] ?? ucfirst($col['field'] ?? ''),
                $columns
            );
            fputcsv($handle, $headers);

            // Data rows.
            foreach ($result->rows as $row) {
                $cells = [];

                foreach ($columns as $col) {
                    $field = $col['field'] ?? $col['name'] ?? null;
                    $format = $col['format'] ?? $col['type'] ?? null;
                    $value = $field !== null ? data_get($row, $field) : null;
                    $cells[] = $this->formatter->format($value, $format);
                }

                fputcsv($handle, $cells);
            }

            // Aggregate footer row (optional).
            if ($result->aggregates && ($options['include_aggregates'] ?? true)) {
                $footer = array_fill(0, count($columns), '');
                $aggregateValues = array_values($result->aggregates);
                // Place aggregate values in the last cells.
                foreach ($aggregateValues as $i => $val) {
                    $idx = count($columns) - count($aggregateValues) + $i;
                    if ($idx >= 0) {
                        $footer[$idx] = $val;
                    }
                }
                fputcsv($handle, $footer);
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
