<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Renderers;

use ElgiborSolution\AdvancedReports\Contracts\ReportRenderer;
use ElgiborSolution\AdvancedReports\Engine\ReportResult;
use ElgiborSolution\AdvancedReports\Exceptions\MissingDependencyException;
use ElgiborSolution\AdvancedReports\Support\Formatter;
use ElgiborSolution\AdvancedReports\Support\PresentationTableRows;
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
 * Supports column-aligned grouping/subtotal rows, grand totals, and
 * format-based number formatting.
 */
final class ExcelRenderer implements ReportRenderer, FromCollection, WithHeadings, WithTitle, WithColumnFormatting, WithStyles
{
    use Exportable;

    protected ReportResult $result;

    protected array $options = [];

    /** @var array<int,string> Worksheet cell references for averages of integer fields. */
    protected array $averageCellFormats = [];

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
        $this->options = $options;
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
        $columns = $this->result->columns;
        $sourceRows = $this->result->rows instanceof \Illuminate\Support\LazyCollection
            ? $this->result->rows->collect()
            : $this->result->rows;
        $presentationRows = $this->result->presentationRowsWithDetails();
        if ($presentationRows === []) {
            $presentationRows = [];
            for ($index = 0; $index < $sourceRows->count(); $index++) {
                $presentationRows[] = ['type' => 'detail', 'row_index' => $index];
            }
        }

        $rows = collect();
        $this->averageCellFormats = [];
        foreach ($presentationRows as $presentationRow) {
            if (in_array($presentationRow['type'] ?? null, ['group_subtotal', 'grand_total'], true)
                && ! ($this->options['include_aggregates'] ?? true)) {
                continue;
            }

            if (in_array($presentationRow['type'] ?? null, ['group_subtotal', 'grand_total'], true)
                && ! PresentationTableRows::hasLabelCell($presentationRow, $columns)) {
                $rows->push(PresentationTableRows::labelCells($presentationRow, $columns));
            }

            $excelRow = $rows->count() + 2; // Heading occupies row 1.
            $rows->push(PresentationTableRows::cells($presentationRow, $sourceRows, $columns, $this->formatter));

            if (in_array($presentationRow['type'] ?? null, ['group_subtotal', 'grand_total'], true)) {
                foreach ($columns as $columnIndex => $column) {
                    $field = $column['field'] ?? $column['name'] ?? '';
                    $format = $column['format'] ?? $column['type'] ?? null;
                    foreach ($presentationRow['aggregate_cells'][$field] ?? [] as $aggregate) {
                        if (strtolower((string) ($aggregate['function'] ?? '')) === 'avg' && $format === 'integer') {
                            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex + 1).$excelRow;
                            $this->averageCellFormats[] = $cell;
                        }
                    }
                }
            }
        }

        return $rows;
    }

    public function headings(): array
    {
        return array_map(fn ($col) => $col['label'] ?? ucfirst($col['field'] ?? ''), $this->result->columns);
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

        foreach ($this->averageCellFormats as $cell) {
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0.00');
        }

        // Auto-width
        foreach (range(1, count($this->result->columns)) as $i) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }
    }
}
