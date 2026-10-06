<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Renderers;

use ElgiborSolution\AdvancedReports\Contracts\ReportRenderer;
use ElgiborSolution\AdvancedReports\Engine\ReportResult;
use ElgiborSolution\AdvancedReports\Exceptions\MissingDependencyException;
use ElgiborSolution\AdvancedReports\Support\Formatter;
use ElgiborSolution\AdvancedReports\Support\HeaderFooterStyle;
use ElgiborSolution\AdvancedReports\Support\PageNumberSettings;
use ElgiborSolution\AdvancedReports\Support\PresentationTableRows;
use ElgiborSolution\AdvancedReports\Support\ReportInfoSettings;
use ElgiborSolution\AdvancedReports\Support\SummaryRowLayout;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

/**
 * Excel renderer powered by Laravel Excel (maatwebsite/excel).
 * Supports column-aligned grouping/subtotal rows, grand totals, and
 * format-based number formatting.
 */
final class ExcelRenderer implements ReportRenderer, FromCollection, WithHeadings, WithTitle, WithColumnFormatting, WithStyles, WithEvents
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

        // Keep the designer's table-border setting, defaulting to its prior
        // visible behavior for reports saved before that setting existed.
        if (($this->result->layout['showBorders'] ?? true) !== false) {
            $sheet->getStyle("A1:{$lastCol}{$lastRow}")
                ->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                        ],
                    ],
                ]);
        }

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

    /** Apply page layout and repeating print areas from the saved designer layout. */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $layout = $this->result->layout ?? [];
                $pageSetup = $sheet->getPageSetup();

                if (array_key_exists('pageSize', $layout)) {
                    $pageSetup->setPaperSize(match (strtolower((string) $layout['pageSize'])) {
                        'letter' => PageSetup::PAPERSIZE_LETTER,
                        'legal' => PageSetup::PAPERSIZE_LEGAL,
                        default => PageSetup::PAPERSIZE_A4,
                    });
                }
                if (array_key_exists('orientation', $layout)) {
                    $pageSetup->setOrientation($layout['orientation'] === 'landscape'
                        ? PageSetup::ORIENTATION_LANDSCAPE
                        : PageSetup::ORIENTATION_PORTRAIT);
                }
                // The Report Info block is inserted as worksheet rows before
                // the table. Repeat only its shifted column-heading row.
                $headingRow = $this->insertReportInfoRows($sheet);
                $pageSetup->setRowsToRepeatAtTopByStartAndEnd($headingRow, $headingRow);

                $headerFooter = $sheet->getHeaderFooter();
                $headerText = trim((string) ($layout['headerText'] ?? ''));
                $footerText = trim((string) ($layout['footerText'] ?? ''));
                $headerStyle = HeaderFooterStyle::normalize(is_array($layout['headerStyle'] ?? null) ? $layout['headerStyle'] : null);
                $footerStyle = HeaderFooterStyle::normalize(is_array($layout['footerStyle'] ?? null) ? $layout['footerStyle'] : null);
                $settings = PageNumberSettings::normalize($layout);
                // Excel supports fixed L/C/R print sections, not arbitrary CSS regions.
                // Keep footer counters on the same row in a distinct section.
                $geometry = PageNumberSettings::geometry($layout, $layout['pageSize'] ?? 'a4', $layout['orientation'] ?? 'portrait');
                foreach (['header' => [$headerText, $headerStyle], 'footer' => [$footerText, $footerStyle]] as $target => [$text, $style]) {
                    $sections = ['left' => '', 'center' => '', 'right' => ''];
                    $size = HeaderFooterStyle::fontSizeInPoints($style);
                    $hasNumbers = $settings['enabled'] && $settings['position'] === $target;
                    $inline = $target === 'footer' && $hasNumbers;
                    $sectionWidth = ($geometry['width'] - 80) / 3 - ($inline ? $settings['contentSpacing'] : 0);
                    if ($inline) {
                        // A centered counter extends into both adjacent sections.
                        // Bound content by the reserved counter width, not only
                        // the nominal one-third Excel section width.
                        $available = $geometry['width'] - 80 - $geometry['footer']['numberWidth'];
                        $sectionWidth = min($sectionWidth, $available / ($settings['alignment'] === 'center' ? 2 : 1) - $settings['contentSpacing']);
                    }
                    $lines = $text === '' ? [] : PageNumberSettings::wrap($text, max(1, $sectionWidth), fn ($line) => mb_strlen($line) * $size);
                    $content = $lines === [] ? '' : HeaderFooterStyle::excelText($style, implode("\n", $lines));
                    $numberSize = PageNumberSettings::fontSize($settings);
                    $gapLines = $content !== '' ? (int) ceil($settings['contentSpacing'] / max(1, $numberSize)) : 0;
                    $numberStyle = [...$style, 'fontSize' => $settings['fontSize'], 'fontSizeUnit' => $settings['fontSizeUnit'], 'textColor' => $settings['textColor']];
                    $label = str_replace(['__PAGE_CODE__', '__TOTAL_CODE__'], ['&P', '&N'], HeaderFooterStyle::excelText($numberStyle, 'Page __PAGE_CODE__ of __TOTAL_CODE__'));
                    $contentAlignment = $style['alignment'];
                    if ($inline && ($contentAlignment === 'center' || $contentAlignment === $settings['alignment'])) {
                        // Excel cannot align text inside a custom-width region:
                        // use the opposite outer section instead of overlapping.
                        $contentAlignment = $settings['alignment'] === 'left' ? 'right' : 'left';
                    }
                    foreach (array_keys($sections) as $alignment) {
                        $contentHere = $alignment === $contentAlignment ? $content : '';
                        if (! $hasNumbers) {
                            $sections[$alignment] = $contentHere;
                            continue;
                        }
                        $numberHere = $alignment === $settings['alignment'] ? $label : '';
                        if ($inline) {
                            $sections[$alignment] = $contentHere.$numberHere;
                            continue;
                        }
                        $sections[$alignment] = $target === 'header'
                            ? $numberHere.($content !== '' ? str_repeat("\n", 1 + $gapLines) : '').$contentHere
                            : $contentHere.($content !== '' ? str_repeat("\n", count($lines) + $gapLines - ($contentHere !== '' ? count($lines) - 1 : 0)) : '').$numberHere;
                    }
                    $encoded = HeaderFooterStyle::excelSections($sections);
                    $target === 'header' ? $headerFooter->setOddHeader($encoded) : $headerFooter->setOddFooter($encoded);
                    $edge = $hasNumbers ? $settings['edgeSpacing'] : 18;
                    $height = $inline ? max(count($lines) * $size * 1.4, $numberSize * 1.4)
                        : count($lines) * $size * 1.4 + ($hasNumbers ? $numberSize * 1.4 + $gapLines * $numberSize * 1.4 : 0);
                    $margins = $sheet->getPageMargins();
                    if ($target === 'header') {
                        $margins->setHeader($edge / 72);
                        $margins->setTop(max($margins->getTop(), ($edge + $height + 12) / 72));
                    } else {
                        $margins->setFooter($edge / 72);
                        $margins->setBottom(max($margins->getBottom(), ($edge + $height + 12) / 72));
                    }
                }
            },
        ];
    }

    /**
     * Add report metadata before the table without turning it into a repeated
     * page header or changing detail/summary row data.
     */
    protected function insertReportInfoRows(Worksheet $sheet): int
    {
        $info = ReportInfoSettings::resolve($this->result);
        if (! $info['visible'] || $info['lines'] === []) {
            return 1;
        }

        $lines = $info['lines'];
        $style = $info['style'];
        $spacing = (float) $style['spacing'];
        $spacerRows = $spacing > 0 ? 1 : 0;
        $insertCount = count($lines) + $spacerRows;
        $sheet->insertNewRowBefore(1, $insertCount);

        $columnCount = max(1, count($this->result->columns));
        $lastColumn = Coordinate::stringFromColumnIndex($columnCount);
        $maxCharacters = max(24, $columnCount * 18);
        $row = 1;

        foreach ($lines as $line) {
            $range = 'A'.$row.':'.$lastColumn.$row;
            if ($columnCount > 1) {
                $sheet->mergeCells($range);
            }
            $sheet->setCellValue('A'.$row, $line['text']);

            $fontSize = ReportInfoSettings::fontSizeInPoints($style);
            $fontColor = $style['textColor'];
            $font = [
                'name' => $style['fontFamily'],
                'size' => $fontSize,
                'bold' => $style['bold'],
                'italic' => $style['italic'],
                'underline' => $style['underline'] ? Font::UNDERLINE_SINGLE : Font::UNDERLINE_NONE,
                'color' => ['argb' => 'FF'.strtoupper(substr($fontColor, 1))],
            ];
            $fill = $style['backgroundColor'] !== null
                ? ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.strtoupper(substr($style['backgroundColor'], 1))]]
                : ['fillType' => Fill::FILL_NONE];

            $sheet->getStyle($range)->applyFromArray([
                'font' => $font,
                'alignment' => [
                    'horizontal' => match ($style['alignment']) {
                        'center' => Alignment::HORIZONTAL_CENTER,
                        'right' => Alignment::HORIZONTAL_RIGHT,
                        default => Alignment::HORIZONTAL_LEFT,
                    },
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
                'fill' => $fill,
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_NONE]],
            ]);
            $this->clearCellBorders($sheet, $range);

            $lineCount = 0;
            foreach (preg_split('/\\R/u', $line['text']) ?: [$line['text']] as $textLine) {
                $lineCount += max(1, (int) ceil(mb_strlen($textLine) / $maxCharacters));
            }
            $sheet->getRowDimension($row)->setRowHeight(max($fontSize * 1.4, $fontSize * 1.35 * $lineCount));
            $row++;
        }

        if ($spacerRows > 0) {
            $spacerRange = 'A'.$row.':'.$lastColumn.$row;
            $sheet->getStyle($spacerRange)->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_NONE],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_NONE]],
            ]);
            $this->clearCellBorders($sheet, $spacerRange);
            $sheet->getRowDimension($row)->setRowHeight(max(1, $spacing));
        }

        return $insertCount + 1;
    }

    protected function clearCellBorders(Worksheet $sheet, string $range): void
    {
        [[$startColumn, $startRow], [$endColumn, $endRow]] = Coordinate::rangeBoundaries($range);
        for ($row = $startRow; $row <= $endRow; $row++) {
            for ($column = $startColumn; $column <= $endColumn; $column++) {
                $cellBorders = $sheet->getStyle(Coordinate::stringFromColumnIndex($column).$row)->getBorders();
                foreach ([$cellBorders->getTop(), $cellBorders->getBottom(), $cellBorders->getLeft(), $cellBorders->getRight()] as $border) {
                    $border->setBorderStyle(Border::BORDER_NONE);
                }
            }
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
