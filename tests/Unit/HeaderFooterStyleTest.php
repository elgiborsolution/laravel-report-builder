<?php

declare(strict_types=1);

use ElgiborSolution\AdvancedReports\Support\HeaderFooterStyle;
use ElgiborSolution\AdvancedReports\Definitions\ReportDefinition;
use ElgiborSolution\AdvancedReports\Engine\ReportResult;
use ElgiborSolution\AdvancedReports\Models\Report;
use ElgiborSolution\AdvancedReports\Renderers\ExcelRenderer;
use ElgiborSolution\AdvancedReports\Support\Formatter;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Sheet as ExcelSheet;
use PHPUnit\Framework\TestCase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class HeaderFooterStyleTest extends TestCase
{
    public function test_it_normalizes_configured_style_values_and_emits_safe_html_and_excel_presentation(): void
    {
        $style = [
            'alignment' => 'center',
            'fontFamily' => 'Helvetica',
            'fontSize' => 12,
            'fontSizeUnit' => 'px',
            'bold' => true,
            'italic' => true,
            'underline' => true,
            'textColor' => '#123456',
            'backgroundColor' => '#abcdef',
            'padding' => 8,
            'paddingUnit' => 'px',
            'borderStyle' => 'dashed',
            'borderColor' => '#654321',
            'borderWidth' => 2,
            'borderWidthUnit' => 'pt',
        ];

        $css = HeaderFooterStyle::css($style);
        self::assertStringContainsString('text-align: center', $css);
        self::assertStringContainsString('font-family: Helvetica, Arial, sans-serif', $css);
        self::assertStringContainsString('font-size: 12px', $css);
        self::assertStringContainsString('background-color: #abcdef', $css);
        self::assertStringContainsString('padding: 8px', $css);
        self::assertStringContainsString('border: 2pt dashed #654321', $css);
        self::assertSame('&"Helvetica,Bold Italic"&9&U&K123456A&&B', HeaderFooterStyle::excelText($style, 'A&B'));
        self::assertSame(9.0, HeaderFooterStyle::fontSizeInPoints($style));
        self::assertSame(6.0, HeaderFooterStyle::paddingInPoints($style));
    }

    public function test_it_falls_back_safely_for_unsupported_units_fonts_colors_and_css_values(): void
    {
        $normalized = HeaderFooterStyle::normalize([
            'alignment' => 'justify',
            'fontFamily' => 'url(javascript:alert(1))',
            'fontSize' => 1000,
            'fontSizeUnit' => 'vh',
            'textColor' => 'red;display:none',
            'backgroundColor' => 'url(evil)',
            'padding' => -5,
            'borderStyle' => 'expression',
        ]);

        self::assertSame(
            [
            'alignment' => 'left',
            'fontFamily' => 'Arial',
            'fontSize' => 24.0,
            'fontSizeUnit' => 'pt',
            'textColor' => '#4b5563',
            'backgroundColor' => '#ffffff',
            'padding' => 0.0,
            'borderStyle' => 'none',
            ],
            array_intersect_key($normalized, array_flip([
                'alignment', 'fontFamily', 'fontSize', 'fontSizeUnit', 'textColor', 'backgroundColor', 'padding', 'borderStyle',
            ])),
        );
    }

    public function test_formatted_header_and_footer_survive_an_xlsx_round_trip(): void
    {
        $style = [
            'alignment' => 'center', 'fontFamily' => 'Arial', 'fontSize' => 11, 'fontSizeUnit' => 'pt',
            'bold' => true, 'textColor' => '#123456',
        ];
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'Report data');
        $sheet->getHeaderFooter()->setOddHeader(HeaderFooterStyle::excelSections([
            'left' => '',
            'center' => HeaderFooterStyle::excelText($style, 'Header & Co'),
            'right' => '',
        ]));
        $pageNumbers = HeaderFooterStyle::excelText($style, 'Page __PAGE__ of __TOTAL__');
        $sheet->getHeaderFooter()->setOddFooter(HeaderFooterStyle::excelSections([
            'left' => '',
            'center' => '',
            'right' => str_replace(['__PAGE__', '__TOTAL__'], ['&P', '&N'], $pageNumbers),
        ]));
        $path = tempnam(sys_get_temp_dir(), 'report-header-footer-');

        try {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);
            $reopened = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            $reopenedSheet = $reopened->getActiveSheet();

            self::assertSame('Report data', $reopenedSheet->getCell('A1')->getValue());
            self::assertSame(
                '&L&C&"Arial,Bold"&11&K123456Header && Co&R',
                $reopenedSheet->getHeaderFooter()->getOddHeader(),
            );
            self::assertSame(
                '&L&C&R&"Arial,Bold"&11&K123456Page &P of &N',
                $reopenedSheet->getHeaderFooter()->getOddFooter(),
            );
            $reopened->disconnectWorksheets();
        } finally {
            $spreadsheet->disconnectWorksheets();
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_excel_renderer_registers_styled_content_alignment_and_page_numbers(): void
    {
        $layout = [
            'headerText' => 'Sales & Service',
            'footerText' => 'Confidential',
            'showPageNumbers' => true,
            'showReportInfo' => false,
            'headerStyle' => [
                'alignment' => 'center', 'fontFamily' => 'Arial', 'fontSize' => 12, 'fontSizeUnit' => 'px',
                'bold' => true, 'italic' => true, 'underline' => true, 'textColor' => '#123456',
            ],
            'footerStyle' => [
                'alignment' => 'right', 'fontFamily' => 'Times New Roman', 'fontSize' => 10, 'fontSizeUnit' => 'pt',
                'italic' => true, 'textColor' => '#654321',
            ],
        ];
        $spreadsheet = new Spreadsheet();
        $worksheet = $spreadsheet->getActiveSheet();
        $excelSheet = (new \ReflectionClass(ExcelSheet::class))->newInstanceWithoutConstructor();
        $delegate = new \ReflectionProperty(ExcelSheet::class, 'worksheet');
        $delegate->setAccessible(true);
        $delegate->setValue($excelSheet, $worksheet);
        $definition = ReportDefinition::fromArray([
            'name' => 'Header/footer test',
            'data_source' => 'test_source',
            'columns' => [['field' => 'amount', 'label' => 'Amount']],
            'layout' => $layout,
        ]);
        $result = new ReportResult(
            new Report(), $definition, [], new Collection(), [['field' => 'amount', 'label' => 'Amount']], layout: $layout,
        );
        $renderer = new ExcelRenderer(new Formatter());
        $resultProperty = new \ReflectionProperty(ExcelRenderer::class, 'result');
        $resultProperty->setAccessible(true);
        $resultProperty->setValue($renderer, $result);
        $event = new AfterSheet($excelSheet, $renderer);
        $renderer->registerEvents()[AfterSheet::class]($event);

        self::assertSame('&L&C&"Arial,Bold Italic"&9&U&K123456Sales && Service&R', $worksheet->getHeaderFooter()->getOddHeader());
        self::assertSame('&L&"Times New Roman,Italic"&9&K000000Page &P of &N&C&R&"Times New Roman,Italic"&10&K654321Confidential', $worksheet->getHeaderFooter()->getOddFooter());
        self::assertSame([1, 1], $worksheet->getPageSetup()->getRowsToRepeatAtTop());

        $spreadsheet->disconnectWorksheets();
    }
}
