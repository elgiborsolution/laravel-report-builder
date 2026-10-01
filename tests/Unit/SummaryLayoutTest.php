<?php

declare(strict_types=1);

use ElgiborSolution\AdvancedReports\Definitions\ReportDefinitionValidator;
use ElgiborSolution\AdvancedReports\Exceptions\DefinitionInvalidException;
use ElgiborSolution\AdvancedReports\Facades\AdvancedReports;
use ElgiborSolution\AdvancedReports\Http\Requests\StoreReportRequest;
use ElgiborSolution\AdvancedReports\Http\Requests\UpdateReportRequest;
use ElgiborSolution\AdvancedReports\Models\Report;
use ElgiborSolution\AdvancedReports\Renderers\ExcelRenderer;
use ElgiborSolution\AdvancedReports\Support\SummaryRowLayout;
use ElgiborSolution\AdvancedReports\Tests\Fixtures\SalesOrderReportSource;
use ElgiborSolution\AdvancedReports\Tests\TestCase;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

uses(TestCase::class);

$columns = [
    ['field' => 'order_number', 'label' => 'Order'],
    ['field' => 'order_date', 'label' => 'Date'],
    ['field' => 'customer_name', 'label' => 'Customer'],
    ['field' => 'total_amount', 'label' => 'Amount', 'format' => 'decimal', 'alignment' => 'right'],
];

$aggregates = [
    ['field' => 'total_amount', 'function' => 'sum', 'label' => 'Amount total'],
    ['field' => 'order_number', 'function' => 'count', 'label' => 'Orders'],
];

$row = static fn (string $type = 'group_subtotal', array $only = [0, 1]) => [
    'type' => $type,
    'aggregate_cells' => array_filter([
        'total_amount' => in_array(0, $only, true) ? [['index' => 0, 'label' => 'Amount total', 'function' => 'sum', 'value' => 6000]] : null,
        'order_number' => in_array(1, $only, true) ? [['index' => 1, 'label' => 'Orders', 'function' => 'count', 'value' => 2]] : null,
    ]),
];

$resolve = static fn (array $row, array $columns, array $aggregates, ?array $layout = null) => SummaryRowLayout::resolve(
    $row,
    $columns,
    $aggregates,
    $layout,
    static fn (array $aggregate, ?string $format) => $format === 'currency' ? 'Rp'.$aggregate['value'] : $aggregate['value'],
);

$shape = static fn (array $resolved) => array_map(
    static fn (array $segment) => [$segment['start'], $segment['span'], $segment['kind'], $segment['text']],
    $resolved['segments'],
);

$withOutput = static fn (array $aggregates, array $outputs) => array_map(
    static fn (array $aggregate, int $index) => array_key_exists($index, $outputs) ? $aggregate + ['output' => $outputs[$index]] : $aggregate,
    $aggregates,
    array_keys($aggregates),
);

beforeEach(function () use ($columns) {
    AdvancedReports::registerSource(SalesOrderReportSource::class);

    $this->createReport = function (string $code, array $aggregates, ?array $layout = null) use ($columns): Report {
        return Report::create([
            'name' => 'Summary '.$code,
            'code' => $code,
            'data_source' => 'sales_orders',
            'definition' => [
                'name' => 'Summary '.$code,
                'data_source' => 'sales_orders',
                'columns' => $columns,
                'groups' => [['field' => 'customer_name', 'label' => 'Customer']],
                'aggregates' => $aggregates,
                'layout' => $layout,
            ],
            'is_active' => true,
            'is_public' => true,
        ]);
    };
});

it('keeps the original placement for reports without output settings', function () use ($resolve, $shape, $row, $columns, $aggregates) {
    $subtotal = $resolve($row(), $columns, $aggregates);
    $grandTotal = $resolve($row('grand_total'), $columns, $aggregates);

    // Values under their own field; the row name in the first free column.
    expect($shape($subtotal))->toBe([
        [0, 1, 'value', 2],
        [1, 1, 'label', 'Subtotal'],
        [2, 1, 'empty', ''],
        [3, 1, 'value', 6000],
    ])
        ->and($shape($grandTotal)[1])->toBe([1, 1, 'label', 'Grand total'])
        ->and($subtotal['label_row'])->toBeNull()
        ->and($subtotal['segments'][3]['align'])->toBe('right')
        ->and($subtotal['segments'][3]['style']['bold'])->toBeTrue();
});

it('migrates earlier per-row summary settings onto the aggregates', function () use ($resolve, $shape, $row, $columns, $aggregates) {
    $aggregates[1]['summary_columns'] = ['subtotal' => 'customer_name'];
    $layout = ['summary' => ['subtotal' => [
        'label_column' => 'order_number',
        'label_colspan' => 4,
        'style' => ['background_color' => '#fef3c7', 'label_align' => 'center'],
    ]]];

    $outputs = SummaryRowLayout::outputs($aggregates, $layout);
    expect($outputs[0]['label'])->toMatchArray(['show' => true, 'column' => 'order_number', 'colspan' => 4, 'align' => 'center'])
        ->and($outputs[1]['label']['show'])->toBeFalse()
        ->and($outputs[1]['column'])->toBe('customer_name');

    // One set of settings now applies to subtotal and grand total alike.
    foreach (['group_subtotal', 'grand_total'] as $type) {
        expect(array_column($shape($resolve($row($type), $columns, $aggregates, $layout)), 1))->toBe([2, 1, 1]);
    }
});

it('applies each aggregate\'s own value column, label, span, alignment and format', function () use ($resolve, $shape, $row, $columns, $aggregates, $withOutput) {
    $aggregates = $withOutput($aggregates, [
        0 => ['column' => 'customer_name', 'align' => 'center', 'format' => 'currency', 'style' => ['background_color' => '#dbeafe'],
            'label' => ['show' => true, 'text' => 'Total amount', 'column' => 'order_number', 'colspan' => 2]],
        1 => ['column' => '@last', 'style' => ['bold' => false],
            'label' => ['show' => false]],
    ]);

    $resolved = $resolve($row(), $columns, $aggregates);
    expect($shape($resolved))->toBe([
        [0, 2, 'label', 'Total amount'],
        [2, 1, 'value', 'Rp6000'],
        [3, 1, 'value', 2],
    ])
        ->and($resolved['segments'][1]['align'])->toBe('center')
        ->and($resolved['segments'][1]['style']['background_color'])->toBe('#dbeafe')
        ->and($resolved['segments'][0]['style']['background_color'])->toBe('#dbeafe')
        ->and($resolved['segments'][2]['style']['bold'])->toBeFalse()
        ->and($resolved['segments'][2]['values'][0]['format'])->toBe('integer');

    // The same settings apply to the grand total; only the default label text differs.
    expect($shape($resolve($row('grand_total'), $columns, $aggregates)))->toBe($shape($resolved));
});

it('never overlaps cells: labels skip occupied columns and spans stop at values, labels and the edge', function () use ($resolve, $shape, $row, $columns, $aggregates, $withOutput) {
    $aggregates = $withOutput($aggregates, [
        0 => ['label' => ['show' => true, 'text' => 'A', 'column' => 'total_amount', 'colspan' => 9]],
        1 => ['column' => 'order_date', 'label' => ['show' => true, 'text' => 'B', 'column' => 'customer_name', 'colspan' => 9]],
    ]);

    $resolved = $resolve($row(), $columns, $aggregates);
    // A wanted the Amount value column, so it takes the first free one and stops before Date's value.
    expect($shape($resolved))->toBe([
        [0, 1, 'label', 'A'],
        [1, 1, 'value', 2],
        [2, 1, 'label', 'B'],
        [3, 1, 'value', 6000],
    ])
        ->and(array_sum(array_column($resolved['segments'], 'span')))->toBe(count($columns));
});

it('shows no label when no aggregate asks for one, and stacks values sharing a column', function () use ($resolve, $shape, $row, $columns, $aggregates, $withOutput) {
    $aggregates = $withOutput($aggregates, [
        0 => ['style' => ['text_color' => '#991b1b']],
        1 => ['column' => 'total_amount', 'style' => ['text_color' => '#166534']],
    ]);

    $resolved = $resolve($row(), $columns, $aggregates);
    expect($shape($resolved))->toBe([
        [0, 1, 'empty', ''],
        [1, 1, 'empty', ''],
        [2, 1, 'empty', ''],
        [3, 1, 'value', 'Amount total: 6000 | Orders: 2'],
    ])
        ->and($resolved['segments'][3]['style']['text_color'])->toBe('#991b1b');
});

it('follows reordering, the rightmost column, and falls back for removed targets', function () use ($resolve, $shape, $row, $columns, $aggregates, $withOutput) {
    $aggregates = $withOutput($aggregates, [
        0 => ['column' => 'removed_field', 'label' => ['show' => true, 'column' => '@last']],
        1 => ['column' => '@last'],
    ]);
    $reordered = [$columns[3], $columns[2], $columns[0]];

    // Amount (own column) is first now; the count goes to the rightmost column,
    // so the label's rightmost target is taken and it uses the first free one.
    expect($shape($resolve($row(), $reordered, $aggregates)))->toBe([
        [0, 1, 'value', 6000],
        [1, 1, 'label', 'Subtotal'],
        [2, 1, 'value', 2],
    ]);
});

it('moves labels without a free column to their own row', function () use ($resolve, $row, $columns, $aggregates) {
    $resolved = $resolve($row('grand_total', [0]), [$columns[3]], $aggregates);

    expect($resolved['label_row'])->toMatchArray(['text' => 'Grand total', 'align' => 'left'])
        ->and($resolved['segments'])->toHaveCount(1);
});

it('sanitizes output settings before they reach CSS', function () {
    $output = SummaryRowLayout::output([
        'column' => 5,
        'align' => 'justify',
        'format' => 'date',
        'style' => ['text_color' => 'red; background: url(x)', 'background_color' => '#FFEEDD', 'bold' => false,
            'border_style' => 'double', 'border_position' => 'top', 'border_color' => '#123'],
        'label' => ['show' => 1, 'text' => str_repeat('x', 150), 'colspan' => 'abc'],
    ]);

    expect($output['column'])->toBeNull()
        ->and($output['align'])->toBeNull()
        ->and($output['format'])->toBeNull()
        ->and($output['style']['text_color'])->toBeNull()
        ->and($output['label']['text'])->toHaveLength(100)
        ->and($output['label']['colspan'])->toBe(1)
        ->and(SummaryRowLayout::css($output['style'], 'right'))->toBe('text-align: right; font-weight: 400; background: #FFEEDD; border-top: 3px double #123')
        ->and(SummaryRowLayout::css(null, null))->toBe('');
});

it('reports invalid output settings without rejecting unknown column references', function () {
    $errors = SummaryRowLayout::validationErrors(null, [
        ['field' => 'total_amount', 'output' => [
            'column' => 'not_a_column',
            'align' => 'justify',
            'format' => 'date',
            'style' => ['text_color' => 'blue', 'bold' => 'yes'],
            'label' => ['show' => 'yes', 'colspan' => 0, 'column' => 7],
        ]],
        ['field' => 'order_number', 'output' => 'nope'],
        ['field' => 'order_number'],
    ]);

    expect(array_keys($errors))->toBe([
        'aggregates.0.output.align',
        'aggregates.0.output.format',
        'aggregates.0.output.style.text_color',
        'aggregates.0.output.style.bold',
        'aggregates.0.output.label.show',
        'aggregates.0.output.label.column',
        'aggregates.0.output.label.colspan',
        'aggregates.1.output',
    ]);

    $definition = AdvancedReports::definition([
        'name' => 'Invalid output',
        'data_source' => 'sales_orders',
        'columns' => [['field' => 'total_amount']],
        'aggregates' => [['field' => 'total_amount', 'function' => 'sum', 'output' => ['label' => ['colspan' => -1]]]],
    ]);
    expect(fn () => app(ReportDefinitionValidator::class)->validate($definition))
        ->toThrow(DefinitionInvalidException::class);
});

it('keeps aggregate outputs through store/update validation and reload', function () use ($columns, $aggregates, $withOutput) {
    $aggregates = $withOutput($aggregates, [
        0 => ['column' => '@last', 'align' => 'right', 'format' => 'currency', 'style' => ['background_color' => '#f3f4f6'],
            'label' => ['show' => true, 'text' => 'Total', 'column' => 'order_number', 'colspan' => 2, 'align' => 'center']],
        1 => ['column' => 'customer_name', 'label' => ['show' => false]],
    ]);
    $payload = [
        'name' => 'Persisted',
        'code' => 'persisted_outputs',
        'data_source' => 'sales_orders',
        'definition' => [
            'name' => 'Persisted',
            'data_source' => 'sales_orders',
            'columns' => $columns,
            'groups' => [['field' => 'customer_name', 'label' => 'Customer']],
            'aggregates' => $aggregates,
            'layout' => ['title' => 'Sales'],
        ],
    ];

    foreach ([new StoreReportRequest(), new UpdateReportRequest()] as $request) {
        $validated = Validator::make($payload, $request->rules())->validated();
        expect($validated['definition']['aggregates'])->toBe($aggregates)
            ->and($validated['definition']['layout'])->toBe(['title' => 'Sales']);
    }

    $report = Report::create(Validator::make($payload, (new StoreReportRequest())->rules())->validated() + ['is_active' => true, 'is_public' => true]);
    $definition = AdvancedReports::definition(Report::query()->findOrFail($report->id)->definition);

    expect($definition->aggregates)->toBe($aggregates);
});

it('renders each aggregate\'s output in subtotals and grand total across HTML/PDF, CSV and XLSX without changing totals', function () use ($aggregates, $withOutput) {
    ($this->createReport)('outputs_plain', $aggregates);
    ($this->createReport)('outputs_styled', $withOutput($aggregates, [
        0 => ['align' => 'center', 'style' => ['background_color' => '#fef3c7', 'text_color' => '#92400e'],
            'label' => ['show' => true, 'text' => 'Total', 'column' => 'order_number', 'colspan' => 2, 'align' => 'center']],
        1 => ['column' => 'customer_name', 'style' => ['bold' => false, 'border_style' => 'double', 'border_position' => 'top'],
            'label' => ['show' => false]],
    ]));

    $plain = AdvancedReports::run('outputs_plain');
    $styled = AdvancedReports::run('outputs_styled');

    expect($styled->aggregates)->toBe($plain->aggregates)
        ->and(collect($styled->presentationRows)->where('type', 'group_subtotal')->pluck('aggregate_values')->all())
        ->toBe(collect($plain->presentationRows)->where('type', 'group_subtotal')->pluck('aggregate_values')->all());

    $labelCell = '<td colspan="2" data-summary-kind="label" style="text-align: center; font-weight: 700; color: #92400e; background: #fef3c7">Total</td>';
    $countCellStyle = 'data-summary-kind="value" style="font-weight: 400; border-top: 3px double #1f2937">';
    $html = AdvancedReports::render('outputs_styled', 'html');
    // Two group subtotals plus the grand total, all from the same aggregate settings.
    expect(substr_count($html, $labelCell))->toBe(3)
        ->and(substr_count($html, $countCellStyle))->toBe(3)
        ->and($html)->toContain('<td colspan="1" data-summary-kind="value" style="text-align: center; font-weight: 700; color: #92400e; background: #fef3c7">8000</td>')
        ->and($html)->not->toContain('>Subtotal<');

    config()->set('advanced-reports.pdf.driver', 'dompdf');
    app()->register(\Barryvdh\DomPDF\ServiceProvider::class);
    $pdf = app(\ElgiborSolution\AdvancedReports\Renderers\PdfRenderer::class)->render($styled);
    expect(substr((string) $pdf->getContent(), 0, 4))->toBe('%PDF');

    $csvResponse = AdvancedReports::render('outputs_styled', 'csv');
    ob_start();
    $csvResponse->sendContent();
    $csvRows = array_map('str_getcsv', array_values(array_filter(preg_split('/\r\n|\r|\n/', trim(ltrim((string) ob_get_clean(), "\xEF\xBB\xBF"))) ?: [])));
    $totals = array_values(array_filter($csvRows, static fn (array $row) => $row[0] === 'Total'));
    // No merged cells in CSV: the spanned column stays empty so values stay aligned.
    expect($totals)->toHaveCount(3)
        ->and($totals[2])->toBe(['Total', '', '3', '8000']);

    $excel = app(ExcelRenderer::class);
    (new ReflectionProperty($excel, 'result'))->setValue($excel, $styled);
    $rows = $excel->collection();
    $sheet = (new Spreadsheet())->getActiveSheet();
    $sheet->fromArray(array_merge([$excel->headings()], $rows->all()), null, 'A1', true);
    $excel->styles($sheet);

    $totalRows = $rows->keys()->filter(static fn (int $key) => $rows[$key][0] === 'Total')->map(static fn (int $key) => $key + 2)->values();
    expect($totalRows)->toHaveCount(3);
    foreach ($totalRows as $sheetRow) {
        expect($sheet->getMergeCells())->toContain("A{$sheetRow}:B{$sheetRow}")
            ->and($sheet->getStyle("A{$sheetRow}")->getFill()->getStartColor()->getARGB())->toBe('FFFEF3C7')
            ->and($sheet->getStyle("D{$sheetRow}")->getFont()->getColor()->getARGB())->toBe('FF92400E')
            ->and($sheet->getStyle("D{$sheetRow}")->getAlignment()->getHorizontal())->toBe('center')
            ->and($sheet->getStyle("C{$sheetRow}")->getBorders()->getTop()->getBorderStyle())->toBe('double')
            ->and($sheet->getStyle("C{$sheetRow}")->getFont()->getBold())->toBeFalse();
    }
});
