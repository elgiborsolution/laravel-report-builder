<?php

declare(strict_types=1);

use ElgiborSolution\AdvancedReports\Facades\AdvancedReports;
use ElgiborSolution\AdvancedReports\Renderers\HtmlRenderer;
use ElgiborSolution\AdvancedReports\Renderers\JsonRenderer;
use ElgiborSolution\AdvancedReports\Support\Formatter;
use ElgiborSolution\AdvancedReports\Support\PresentationTableRows;
use ElgiborSolution\AdvancedReports\Tests\Fixtures\SalesOrderReportSource;
use ElgiborSolution\AdvancedReports\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    AdvancedReports::registerSource(SalesOrderReportSource::class);

    \ElgiborSolution\AdvancedReports\Models\Report::create([
        'name' => 'Sales',
        'code' => 'sales_json',
        'data_source' => 'sales_orders',
        'definition' => [
            'name' => 'Sales',
            'data_source' => 'sales_orders',
            'parameters' => [
                ['name' => 'date_from', 'default' => '2026-01-01'],
                ['name' => 'date_to', 'default' => '2026-12-31'],
            ],
            'columns' => [
                ['field' => 'order_number', 'label' => 'Order'],
                ['field' => 'total_amount', 'label' => 'Amount', 'format' => 'currency'],
                ['field' => 'amount_with_tax', 'label' => 'Amount with tax', 'format' => 'currency'],
            ],
            'formulas' => [[
                'name' => 'amount_with_tax',
                'label' => 'Amount with tax',
                'expression' => 'total_amount * 1.11',
                'type' => 'decimal',
                'format' => 'currency',
            ]],
            'aggregates' => [
                ['field' => 'customer_name', 'function' => 'count', 'label' => 'Rows'],
                ['field' => 'total_amount', 'function' => 'sum', 'label' => 'Sum'],
                ['field' => 'total_amount', 'function' => 'avg', 'label' => 'Average'],
                ['field' => 'total_amount', 'function' => 'min', 'label' => 'Minimum'],
                ['field' => 'total_amount', 'function' => 'max', 'label' => 'Maximum'],
            ],
        ],
        'is_active' => true,
        'is_public' => true,
    ]);
});

it('renders JSON output with required keys', function () {
    $rendered = AdvancedReports::render('sales_json', 'json');

    expect($rendered)
        ->toHaveKeys(['metadata', 'columns', 'rows', 'groups', 'aggregates', 'drilldowns'])
        ->and($rendered['columns'][2])->toBe([
            'field' => 'amount_with_tax',
            'label' => 'Amount with tax',
            'type' => 'decimal',
            'format' => 'currency',
        ])
        ->and($rendered['rows'][0]['amount_with_tax'])->toContain('1,110')
        ->and($rendered['rows'])->not->toBeEmpty()
        ->and($rendered['aggregates'])->toBe([
            'Rows' => 3,
            'Sum' => 8000.0,
            'Average' => 8000 / 3,
            'Minimum' => 1000.0,
            'Maximum' => 5000.0,
        ]);
});

it('renders HTML output as a string containing a table', function () {
    $html = AdvancedReports::render('sales_json', 'html');

    expect($html)->toBeString()
        ->and($html)->toContain('<table')
        ->and($html)->toContain('Sales')
        ->and($html)->toContain('Amount with tax')
        ->and($html)->toContain('1,110')
        ->and($html)->toContain('SO-001')
        ->and($html)->not->toContain('<div class="report-layout-header">')
        ->and($html)->not->toContain('<div class="report-layout-footer">');
});

it('renders grouping events consistently and aligns group totals to their source columns', function () {
    \ElgiborSolution\AdvancedReports\Models\Report::create([
        'name' => 'Grouped Sales Output',
        'code' => 'grouped_sales_output',
        'data_source' => 'sales_orders',
        'definition' => [
            'name' => 'Grouped Sales Output',
            'data_source' => 'sales_orders',
            'parameters' => [
                ['name' => 'date_from', 'default' => '2026-01-01'],
                ['name' => 'date_to', 'default' => '2026-12-31'],
            ],
            'columns' => [
                ['field' => 'order_number', 'label' => 'Order'],
                ['field' => 'total_amount', 'label' => 'Amount', 'format' => 'decimal'],
            ],
            'groups' => [['field' => 'customer_name', 'label' => 'Customer']],
            'aggregates' => [['field' => 'total_amount', 'function' => 'sum', 'label' => 'Amount total']],
        ],
        'is_active' => true,
        'is_public' => true,
    ]);

    $json = AdvancedReports::render('grouped_sales_output', 'json');
    $presentation = $json['presentation_rows'];
    $subtotal = collect($presentation)->firstWhere('type', 'group_subtotal');
    $grandTotal = collect($presentation)->firstWhere('type', 'grand_total');

    expect($json)->toHaveKeys(['rows', 'groups', 'aggregates', 'presentation_rows'])
        ->and($json['rows'])->toHaveCount(3)
        ->and(collect($presentation)->where('type', 'detail'))->toHaveCount(3)
        ->and($presentation[0]['type'])->toBe('group_header')
        ->and($presentation[0]['label'])->toBe('Customer')
        ->and($subtotal['aggregate_cells']['total_amount'][0]['value'])->toBe(6000.0)
        ->and($grandTotal['aggregate_cells']['total_amount'][0]['value'])->toBe(8000.0);

    $html = AdvancedReports::render('grouped_sales_output', 'html');
    expect($html)->toContain('Customer: Acme')
        ->and($html)->toContain('>Subtotal<')->and($html)->not->toContain('Subtotal: Customer')
        ->and($html)->toContain('Grand total');

    $csvResponse = AdvancedReports::render('grouped_sales_output', 'csv');
    ob_start();
    $csvResponse->sendContent();
    $csv = ltrim((string) ob_get_clean(), "\xEF\xBB\xBF");
    $csvRows = array_map(
        static fn (string $line) => str_getcsv($line),
        array_values(array_filter(preg_split('/\\r\\n|\\r|\\n/', trim($csv)) ?: [])),
    );
    $csvSubtotal = collect($csvRows)->first(static fn (array $row) => ($row[0] ?? '') === 'Subtotal');
    $csvGrandTotal = collect($csvRows)->first(static fn (array $row) => ($row[0] ?? '') === 'Grand total');
    expect($csvSubtotal[1])->toBe('6000')
        ->and($csvGrandTotal[1])->toBe('8000');

    $result = AdvancedReports::run('grouped_sales_output');
    config()->set('advanced-reports.pdf.driver', 'custom');
    app()->bind('grouped-report-test-pdf-engine', static fn () => static fn (string $html, array $options = []) => $html);
    app()->tag('grouped-report-test-pdf-engine', 'advanced-reports.pdf.engine');
    $pdfHtml = app(\ElgiborSolution\AdvancedReports\Renderers\PdfRenderer::class)->render($result);
    expect($pdfHtml)->toContain('Customer: Acme')
        ->and($pdfHtml)->toContain('Grand total');

    $excel = app(\ElgiborSolution\AdvancedReports\Renderers\ExcelRenderer::class);
    $property = new ReflectionProperty($excel, 'result');
    $property->setAccessible(true);
    $property->setValue($excel, $result);
    $excelRows = $excel->collection();
    $excelSubtotal = $excelRows->first(static fn (array $row) => ($row[0] ?? '') === 'Subtotal');
    $excelGrandTotal = $excelRows->first(static fn (array $row) => ($row[0] ?? '') === 'Grand total');
    expect($excelSubtotal[1])->toBe(6000.0)
        ->and($excelGrandTotal[1])->toBe(8000.0);
});

it('preserves average precision for integer fields in presentation cells', function () {
    $row = [
        'type' => 'group_subtotal',
        'label' => 'Subtotal',
        'aggregate_cells' => [
            'quantity' => [['index' => 0, 'label' => 'Average quantity', 'function' => 'avg', 'value' => 1.5]],
        ],
    ];
    $aggregates = [['field' => 'quantity', 'function' => 'avg', 'label' => 'Average quantity']];

    $cells = PresentationTableRows::cells(
        $row,
        collect(),
        [['field' => 'quantity', 'format' => 'integer']],
        new Formatter(),
        $aggregates,
    );

    // The only column holds the value, so the label moves to its own row.
    expect($cells[0])->toBe(1.5)
        ->and(PresentationTableRows::hasLabelCell($row, [['field' => 'quantity']], $aggregates))->toBeFalse()
        ->and(PresentationTableRows::labelCells($row, [['field' => 'quantity']], $aggregates))->toBe(['Subtotal']);
});

it('exports selected formula columns to CSV and XLSX in matching order', function () {
    $csvResponse = AdvancedReports::render('sales_json', 'csv');
    ob_start();
    $csvResponse->sendContent();
    $csv = ob_get_clean();

    expect($csv)
        ->toContain('Amount with tax')
        ->toContain('1,110');

    $result = AdvancedReports::run('sales_json');
    $excel = app(\ElgiborSolution\AdvancedReports\Renderers\ExcelRenderer::class);
    $property = new ReflectionProperty($excel, 'result');
    $property->setAccessible(true);
    $property->setValue($excel, $result);

    expect($excel->headings())->toBe(['Order', 'Amount', 'Amount with tax'])
        ->and($excel->collection()->first()[2])->toContain('1,110');
});

it('exports configured report headers and footers without replacing column or aggregate rows', function () {
    \ElgiborSolution\AdvancedReports\Models\Report::create([
        'name' => 'Configured Layout',
        'code' => 'configured_layout',
        'data_source' => 'sales_orders',
        'definition' => [
            'name' => 'Configured Layout',
            'data_source' => 'sales_orders',
            'parameters' => [
                ['name' => 'date_from', 'default' => '2026-01-01'],
                ['name' => 'date_to', 'default' => '2026-12-31'],
            ],
            'columns' => [
                ['field' => 'order_number', 'label' => 'Order'],
                ['field' => 'total_amount', 'label' => 'Amount', 'format' => 'decimal'],
            ],
            'groups' => [['field' => 'customer_name', 'label' => 'Customer']],
            'aggregates' => [['field' => 'total_amount', 'function' => 'sum', 'label' => 'Amount total']],
            'layout' => [
                'title' => 'Quarterly Sales',
                'subtitle' => 'Q3 operating performance',
                'description' => 'Layout-provided summary',
                'showReportInfo' => true,
                'reportInfoStyle' => [
                    'alignment' => 'center', 'fontFamily' => 'Times New Roman', 'fontSize' => 12, 'fontSizeUnit' => 'pt',
                    'bold' => true, 'italic' => true, 'underline' => true, 'textColor' => '#123456',
                    'backgroundColor' => '#eeeeff', 'spacing' => 14,
                ],
                'headerText' => 'Sales & Service',
                'footerText' => 'Confidential',
                'pageSize' => 'letter',
                'orientation' => 'landscape',
                'showPageNumbers' => true,
                'showBorders' => true,
                'headerStyle' => [
                    'alignment' => 'center', 'fontFamily' => 'Arial', 'fontSize' => 12, 'fontSizeUnit' => 'px',
                    'bold' => true, 'italic' => true, 'underline' => true, 'textColor' => '#123456',
                    'backgroundColor' => '#eeeeff', 'padding' => 5, 'paddingUnit' => 'px',
                    'borderStyle' => 'dashed', 'borderColor' => '#abcdef', 'borderWidth' => 1, 'borderWidthUnit' => 'px',
                ],
                'footerStyle' => [
                    'alignment' => 'right', 'fontFamily' => 'Times New Roman', 'fontSize' => 10, 'fontSizeUnit' => 'pt',
                    'italic' => true, 'textColor' => '#654321', 'borderStyle' => 'solid',
                ],
            ],
        ],
        'is_active' => true,
        'is_public' => true,
    ]);

    $result = AdvancedReports::run('configured_layout');
    $htmlRenderer = app(HtmlRenderer::class);
    $html = $htmlRenderer->render($result);

    expect($html)
        ->toContain('Sales &amp; Service')
        ->toContain('Quarterly Sales')
        ->toContain('Q3 operating performance')
        ->toContain('Layout-provided summary')
        ->toContain('text-align: center; font-family: Times New Roman, serif; font-size: 12pt; font-weight: bold; font-style: italic; text-decoration: underline; color: #123456; background-color: #eeeeff; margin-bottom: 14pt')
        ->toContain('Confidential')
        ->toContain('text-align: center; font-family: Arial, Helvetica, sans-serif; font-size: 12px; font-weight: bold; font-style: italic; text-decoration: underline; color: #123456; background-color: #eeeeff; padding: 5px; border: 1px dashed #abcdef')
        ->toContain('text-align: right; font-family: Times New Roman, serif; font-size: 10pt; font-weight: normal; font-style: italic; text-decoration: none; color: #654321')
        ->toContain('<th>Order</th>')
        ->toContain('Grand total');

    config()->set('advanced-reports.pdf.driver', 'custom');
    $capturedPdf = null;
    app()->bind('configured-layout-pdf-engine', static function () use (&$capturedPdf) {
        return static function (string $pdfHtml, array $options = []) use (&$capturedPdf) {
            $capturedPdf = ['html' => $pdfHtml, 'options' => $options];

            return $capturedPdf;
        };
    });
    app()->tag('configured-layout-pdf-engine', 'advanced-reports.pdf.engine');
    $pdfOutput = app(\ElgiborSolution\AdvancedReports\Export\ExportManager::class)->export($result, 'pdf');

    expect($pdfOutput['options'])
        ->toMatchArray(['paper' => 'letter', 'orientation' => 'landscape', 'show_page_numbers' => true])
        ->and($pdfOutput['html'])
        ->toContain('Sales &amp; Service')
        ->toContain('Quarterly Sales')
        ->toContain('Q3 operating performance')
        ->toContain('Layout-provided summary')
        ->toContain('Confidential')
        ->toContain('background-color: #eeeeff')
        ->toContain('border: 1px dashed #abcdef')
        ->toContain('@page { margin:')
        ->toContain('display: table-header-group')
        ->toContain('display: table-row-group')
        ->toContain('<th>Order</th>')
        ->toContain('Grand total')
        ->and(strpos($pdfOutput['html'], 'Grand total'))->toBeLessThan(strpos($pdfOutput['html'], 'Confidential'));

    $pdfOverride = app(\ElgiborSolution\AdvancedReports\Export\ExportManager::class)->export(
        $result,
        'pdf',
        ['paper' => 'legal', 'orientation' => 'portrait'],
    );
    expect($pdfOverride['options'])->toMatchArray(['paper' => 'legal', 'orientation' => 'portrait']);

    $csvResponse = AdvancedReports::render('configured_layout', 'csv');
    ob_start();
    $csvResponse->sendContent();
    $csv = ltrim((string) ob_get_clean(), "\xEF\xBB\xBF");
    $csvRows = array_map(
        static fn (string $line) => str_getcsv($line),
        array_values(array_filter(preg_split('/\\r\\n|\\r|\\n/', trim($csv)) ?: [])),
    );
    $grandTotalIndex = array_search('Grand total', array_map(static fn (array $row) => $row[0] ?? null, $csvRows), true);

    expect(substr_count($pdfOutput['html'], 'Quarterly Sales'))->toBe(1);

    expect($csvRows[0])->toBe(['Sales & Service', ''])
        ->and($csvRows[1])->toBe(['Order', 'Amount'])
        ->and($grandTotalIndex)->toBeInt()
        ->and($grandTotalIndex)->toBeLessThan(array_key_last($csvRows))
        ->and($csvRows[array_key_last($csvRows)])->toBe(['Confidential', '']);

    $excel = app(\ElgiborSolution\AdvancedReports\Renderers\ExcelRenderer::class);
    $property = new ReflectionProperty($excel, 'result');
    $property->setAccessible(true);
    $property->setValue($excel, $result);
    app()->register(\Maatwebsite\Excel\ExcelServiceProvider::class);
    $xlsx = \Maatwebsite\Excel\Facades\Excel::raw($excel, \Maatwebsite\Excel\Excel::XLSX);
    $path = tempnam(sys_get_temp_dir(), 'report-layout-');
    file_put_contents($path, $xlsx);

    try {
        $workbook = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $workbook->getActiveSheet();
        $pageSetup = $sheet->getPageSetup();

        expect($sheet->getCell('A1')->getValue())->toBe('Quarterly Sales')
            ->and($sheet->getCell('A2')->getValue())->toBe('Q3 operating performance')
            ->and($sheet->getCell('A3')->getValue())->toBe('Layout-provided summary')
            ->and($sheet->getCell('A4')->getValue())->toBeNull()
            ->and($sheet->getCell('A5')->getValue())->toBe('Order')
            ->and($sheet->getStyle('A1')->getFont()->getName())->toBe('Times New Roman')
            ->and($sheet->getStyle('A1')->getFont()->getSize())->toBe(12.0)
            ->and($sheet->getStyle('A1')->getFont()->getBold())->toBeTrue()
            ->and($sheet->getStyle('A1')->getFont()->getItalic())->toBeTrue()
            ->and($sheet->getStyle('A1')->getFont()->getUnderline())->toBe('single')
            ->and($sheet->getStyle('A1')->getFont()->getColor()->getARGB())->toBe('FF123456')
            ->and($sheet->getStyle('A1')->getFill()->getStartColor()->getARGB())->toBe('FFEEEEFF')
            ->and($sheet->getStyle('A1')->getAlignment()->getHorizontal())->toBe(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
            ->and($sheet->getStyle('A1')->getBorders()->getLeft()->getBorderStyle())->toBe(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_NONE)
            ->and($sheet->getStyle('A4')->getBorders()->getBottom()->getBorderStyle())->toBe(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_NONE)
            ->and($sheet->getHeaderFooter()->getOddHeader())->toBe('&L&C&"Arial,Bold Italic"&9&U&K123456Sales && Service&R')
            ->and($sheet->getHeaderFooter()->getOddFooter())->toContain('Page &P of &N')
            ->and($sheet->getHeaderFooter()->getOddFooter())->toContain('Confidential')
            ->and($sheet->getHeaderFooter()->getOddFooter())->not->toContain("\n")
            ->and($pageSetup->getPaperSize())->toBe(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_LETTER)
            ->and($pageSetup->getOrientation())->toBe(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
            ->and($pageSetup->getRowsToRepeatAtTop())->toBe(['5', '5'])
            ->and($sheet->getStyle('A5')->getBorders()->getLeft()->getBorderStyle())->toBe(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
            ->and(collect($excel->collection())->flatten()->contains('Grand total'))->toBeTrue();

        foreach (['A1', 'B1', 'A2', 'B2', 'A4', 'B4'] as $infoCell) {
            $borders = $sheet->getStyle($infoCell)->getBorders();
            expect([
                $borders->getTop()->getBorderStyle(),
                $borders->getBottom()->getBorderStyle(),
                $borders->getLeft()->getBorderStyle(),
                $borders->getRight()->getBorderStyle(),
            ])->toBe([
                \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_NONE,
                \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_NONE,
                \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_NONE,
                \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_NONE,
            ]);
        }

        $workbook->disconnectWorksheets();
    } finally {
        @unlink($path);
    }
});

it('hides Report Info in HTML and Excel while retaining the table header when disabled', function () {
    \ElgiborSolution\AdvancedReports\Models\Report::create([
        'name' => 'Hidden Report Info',
        'code' => 'hidden_report_info',
        'data_source' => 'sales_orders',
        'definition' => [
            'name' => 'Hidden Report Info',
            'data_source' => 'sales_orders',
            'parameters' => [
                ['name' => 'date_from', 'default' => '2026-01-01'],
                ['name' => 'date_to', 'default' => '2026-12-31'],
            ],
            'columns' => [['field' => 'order_number', 'label' => 'Order']],
            'layout' => [
                'title' => 'Must not display',
                'showReportInfo' => false,
                'reportInfoStyle' => ['bold' => true, 'backgroundColor' => '#ffeecc'],
            ],
        ],
        'is_active' => true,
        'is_public' => true,
    ]);

    $result = AdvancedReports::run('hidden_report_info');
    $html = app(HtmlRenderer::class)->render($result);
    expect($html)->not->toContain('<section class="report-info')
        ->and($html)->toContain('<th>Order</th>');

    $excel = app(\ElgiborSolution\AdvancedReports\Renderers\ExcelRenderer::class);
    $resultProperty = new ReflectionProperty($excel, 'result');
    $resultProperty->setAccessible(true);
    $resultProperty->setValue($excel, $result);
    app()->register(\Maatwebsite\Excel\ExcelServiceProvider::class);
    $xlsx = \Maatwebsite\Excel\Facades\Excel::raw($excel, \Maatwebsite\Excel\Excel::XLSX);
    $path = tempnam(sys_get_temp_dir(), 'report-info-hidden-');
    file_put_contents($path, $xlsx);

    try {
        $workbook = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $workbook->getActiveSheet();
        expect($sheet->getCell('A1')->getValue())->toBe('Order')
            ->and($sheet->getPageSetup()->getRowsToRepeatAtTop())->toBe(['1', '1']);
        $workbook->disconnectWorksheets();
    } finally {
        @unlink($path);
    }
});

it('excludes hidden fields from rendered output', function () {
    $rendered = AdvancedReports::render('sales_json', 'json');

    foreach ($rendered['columns'] as $col) {
        expect($col['field'])->not->toBe('internal_cost');
    }
});
