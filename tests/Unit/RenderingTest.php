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
                'headerText' => 'Sales & Service',
                'footerText' => 'Confidential',
                'pageSize' => 'letter',
                'orientation' => 'landscape',
                'showPageNumbers' => true,
                'showBorders' => true,
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
        ->toContain('Confidential')
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
        ->toContain('Confidential')
        ->toContain('report-page-number')
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

        expect($sheet->getCell('A1')->getValue())->toBe('Order')
            ->and($sheet->getHeaderFooter()->getOddHeader())->toBe('&LSales && Service')
            ->and($sheet->getHeaderFooter()->getOddFooter())->toBe('&LConfidential&RPage &P of &N')
            ->and($pageSetup->getPaperSize())->toBe(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_LETTER)
            ->and($pageSetup->getOrientation())->toBe(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
            ->and($pageSetup->getRowsToRepeatAtTop())->toBe(['1', '1'])
            ->and($sheet->getStyle('A1')->getBorders()->getLeft()->getBorderStyle())->toBe(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
            ->and(collect($excel->collection())->flatten()->contains('Grand total'))->toBeTrue();

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
