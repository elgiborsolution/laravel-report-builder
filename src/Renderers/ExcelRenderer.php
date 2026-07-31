<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Renderers;

use ElgiborSolution\AdvancedReports\Contracts\ReportRenderer;
use ElgiborSolution\AdvancedReports\Engine\ReportResult;
use ElgiborSolution\AdvancedReports\Exceptions\MissingDependencyException;
use ElgiborSolution\AdvancedReports\Support\Formatter;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel renderer powered by Laravel Excel (maatwebsite/excel).
 * Supports columns, groups (as sheets), aggregates (footer row), and
 * format-based number formatting.
 */
final class ExcelRenderer implements ReportRenderer, FromCollection, WithHeadings, WithTitle, WithColumnFormatting, WithStyles
{
    use Exportable;

    protected ReportResult $result;

    public function __construct(protected Formatter $formatter) {}

    public function format(): string
    {
        return 'xlsx';
    }

    public function render(ReportResult $result, array $options = []): mixed
    {
        if (! class_exists(\Maatwebsite\Excel\Facades\Excel::class)) {
            throw MissingDependencyException::forPackage('maatwebsite/excel', 'Excel export');
        }

        $this->result = $result;
        $filename = ($options['filename'] ?? 'report').'_'.now()->format('Y-m-d_His').'.xlsx';

        return \Maatwebsite\Excel\Facades\Excel::download($this, $filename);
    }

    /*
    |--------------------------------------------------------------------------
    | Laravel Excel concerns
    |--------------------------------------------------------------------------
    */

    public function collection(): \Illuminate\Support\Collection
    {
        $rows = $this->result->rows->map(function ($row) {
            foreach ($this->result->columns as $col) {
                $field = $col['field'] ?? $col['name'] ?? null;
                $format = $col['format'] ?? $col['type'] ?? null;
                if ($field !== null && array_key_exists($field, $row)) {
                    $row[$field] = $this->formatter->format($row[$field], $format);
                }
            }
            foreach ($this->result->formulas as $f) {
                $name = $f['name'] ?? null;
                $format = $f['format'] ?? null;
                if ($name !== null && array_key_exists($name, $row)) {
                    $row[$name] = $this->formatter->format($row[$name], $format);
                }
            }

            return $row;
        });

        // Append aggregates as the last row.
        if ($this->result->aggregates) {
            $footer = ['_aggregate_row' => true];
            foreach ($this->result->columns as $col) {
                $field = $col['field'] ?? null;
                $footer[$field] = null;
            }
            foreach ($this->result->aggregates as $label => $value) {
                $footer[$label] = $value;
            }
            $rows->push($footer);
        }

        return $rows;
    }

    public function headings(): array
    {
        return array_map(fn ($col) => $col['label'] ?? ucfirst($col['field'] ?? '')), $this->result->columns);
    }

    public function title(): string
    {
        return $this->result->report->name ?? 'Report';
    }

    public function columnFormats(): array
    {
        $formats = [];
        $columns = $this->result->columns;

        foreach ($columns as $index => $col) {
            $format = $col['format'] ?? $col['type'] ?? 'string';
            // PHPExcel column letters: A, B, C, ...
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);

            $formats[$letter] = match ($format) {
                'currency' => '#,##0.00',
                'decimal' => '#,##0.00',
                'integer' => '#,##0',
                'percentage' => '0.00%',
                'date' => 'YYYY-MM-DD',
                'datetime' => 'YYYY-MM-DD HH:MM:SS',
                default => \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_GENERAL,
            };
        }

        return $formats;
    }

    public function styles(Worksheet $sheet): void
    {
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
            count($this->result->columns)
        );
        $lastRow = $sheet->getHighestRow();

        // Header styling
        $sheet->getStyle("A1:{$lastCol}1")
            ->setFont(['bold' => true])
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Borders
        $sheet->getStyle("A1:{$lastCol}{$lastRow}")
            ->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                    ],
                ],
            ]);

        // Auto-width
        foreach (range(1, count($this->result->columns)) as $i) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }
    }
}
