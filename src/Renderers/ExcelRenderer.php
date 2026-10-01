<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Renderers;

use ElgiborSolution\AdvancedReports\Contracts\ReportRenderer;
use ElgiborSolution\AdvancedReports\Engine\ReportResult;
use ElgiborSolution\AdvancedReports\Exceptions\MissingDependencyException;
use ElgiborSolution\AdvancedReports\Support\Formatter;
use ElgiborSolution\AdvancedReports\Support\PresentationTableRows;
use ElgiborSolution\AdvancedReports\Support\SummaryRowLayout;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
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

    /** @var array<string,string> Cell reference => number format for summary values whose format differs from their column. */
    protected array $averageCellFormats = [];

    /** @var array<int,array<string,mixed>> Resolved summary layouts keyed by worksheet row, styled in styles(). */
    protected array $summaryRows = [];

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
        $this->summaryRows = [];
        $aggregateDefinitions = $this->result->definition->aggregates;
        $layout = $this->result->layout;

        foreach ($presentationRows as $presentationRow) {
            $isSummary = in_array($presentationRow['type'] ?? null, ['group_subtotal', 'grand_total'], true);
            if ($isSummary && ! ($this->options['include_aggregates'] ?? true)) {
                continue;
            }

            if (! $isSummary) {
                $rows->push(PresentationTableRows::cells($presentationRow, $sourceRows, $columns, $this->formatter));
                continue;
            }

            $summary = PresentationTableRows::summary($presentationRow, $columns, $this->formatter, $aggregateDefinitions, $layout);
            $labelRowIndex = null;
            if ($summary['label_row'] !== null) {
                $labelRowIndex = $rows->count() + 2; // Heading occupies row 1.
                $rows->push(PresentationTableRows::labelCells($presentationRow, $columns, $aggregateDefinitions, $layout));
            }

            $excelRow = $rows->count() + 2;
            $rows->push(PresentationTableRows::cells($presentationRow, $sourceRows, $columns, $this->formatter, $aggregateDefinitions, $layout));
            $this->summaryRows[] = ['row' => $excelRow, 'label_row_index' => $labelRowIndex] + $summary;

            // Number formats are per column; a value whose effective format
            // differs (an average of integers, a format override, or a value
            // moved to another column) gets a cell-level format instead.
            foreach ($summary['segments'] as $segment) {
                if ($segment['kind'] !== 'value' || count($segment['values']) !== 1) {
                    continue;
                }

                $valueFormat = $segment['values'][0]['format'] ?? null;
                $columnFormat = $columns[$segment['start']]['format'] ?? $columns[$segment['start']]['type'] ?? null;
                if ($valueFormat !== $columnFormat) {
                    $cell = Coordinate::stringFromColumnIndex($segment['start'] + 1).$excelRow;
                    $this->averageCellFormats[$cell] = $this->formatCode($valueFormat);
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

        foreach ($this->result->columns as $index => $col) {
            $formats[Coordinate::stringFromColumnIndex($index + 1)] = $this->formatCode($col['format'] ?? $col['type'] ?? 'string');
        }

        return $formats;
    }

    public function styles(Worksheet $sheet): void
    {
        $columnCount = count($this->result->columns);
        $lastCol = Coordinate::stringFromColumnIndex($columnCount);
        $lastRow = $sheet->getHighestRow();

        // Header styling
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        // Borders
        $sheet->getStyle("A1:{$lastCol}{$lastRow}")
            ->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                    ],
                ],
            ]);

        foreach ($this->averageCellFormats as $cell => $formatCode) {
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($formatCode);
        }

        foreach ($this->summaryRows as $summary) {
            $this->styleSummaryRow($sheet, $summary, $columnCount);
        }

        // Auto-width
        foreach (range(1, $columnCount) as $i) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }
    }

    /** Apply merged label cells and each aggregate's style, as resolved by SummaryRowLayout. */
    protected function styleSummaryRow(Worksheet $sheet, array $summary, int $columnCount): void
    {
        if ($summary['label_row_index'] !== null && $summary['label_row'] !== null && $columnCount > 0) {
            $row = $summary['label_row_index'];
            $range = 'A'.$row.':'.Coordinate::stringFromColumnIndex($columnCount).$row;
            if ($columnCount > 1) {
                $sheet->mergeCells($range);
            }
            $sheet->getStyle($range)->applyFromArray($this->summaryStyleArray($summary['label_row']['style'], $summary['label_row']['align']));
        }

        foreach ($summary['segments'] as $segment) {
            if ($segment['style'] === null && $segment['span'] === 1) {
                continue;
            }

            $first = Coordinate::stringFromColumnIndex($segment['start'] + 1).$summary['row'];
            $range = $segment['span'] > 1
                ? $first.':'.Coordinate::stringFromColumnIndex($segment['start'] + $segment['span']).$summary['row']
                : $first;

            if ($segment['span'] > 1) {
                $sheet->mergeCells($range);
            }

            if ($segment['style'] !== null) {
                $sheet->getStyle($range)->applyFromArray($this->summaryStyleArray($segment['style'], $segment['align']));
            }
        }
    }

    /** @return array<string,mixed> PhpSpreadsheet style array. */
    protected function summaryStyleArray(array $style, ?string $align): array
    {
        $array = ['font' => ['bold' => (bool) ($style['bold'] ?? true)]];

        if ($style['text_color'] ?? null) {
            $array['font']['color'] = ['argb' => $this->argb($style['text_color'])];
        }

        if ($style['background_color'] ?? null) {
            $array['fill'] = [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => $this->argb($style['background_color'])],
            ];
        }

        if ($align) {
            $array['alignment'] = ['horizontal' => match ($align) {
                'center' => Alignment::HORIZONTAL_CENTER,
                'right' => Alignment::HORIZONTAL_RIGHT,
                default => Alignment::HORIZONTAL_LEFT,
            }];
        }

        $borderStyle = match ($style['border_style'] ?? null) {
            'none' => Border::BORDER_NONE,
            'thin' => Border::BORDER_THIN,
            'thick' => Border::BORDER_THICK,
            'double' => Border::BORDER_DOUBLE,
            default => null,
        };
        if ($borderStyle !== null) {
            $border = ['borderStyle' => $borderStyle];
            if ($style['border_color'] ?? null) {
                $border['color'] = ['argb' => $this->argb($style['border_color'])];
            }
            foreach (SummaryRowLayout::borderSides($style['border_position'] ?? 'all') as $side) {
                $array['borders'][$side] = $border;
            }
        }

        return $array;
    }

    protected function formatCode(?string $format): string
    {
        return match ($format) {
            'currency' => '#,##0.00',
            'decimal' => '#,##0.00',
            'integer' => '#,##0',
            'percentage' => '0.00%',
            'date' => 'YYYY-MM-DD',
            'datetime' => 'YYYY-MM-DD HH:MM:SS',
            default => NumberFormat::FORMAT_GENERAL,
        };
    }

    /** "#abc" / "#aabbcc" (already validated) to PhpSpreadsheet ARGB. */
    protected function argb(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return 'FF'.strtoupper($hex);
    }
}
