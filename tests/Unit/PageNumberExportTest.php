<?php

declare(strict_types=1);

use ElgiborSolution\AdvancedReports\Definitions\ReportDefinition;
use ElgiborSolution\AdvancedReports\Engine\ReportResult;
use ElgiborSolution\AdvancedReports\Models\Report;
use ElgiborSolution\AdvancedReports\Renderers\PdfRenderer;
use ElgiborSolution\AdvancedReports\Renderers\ExcelRenderer;
use ElgiborSolution\AdvancedReports\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $cache = sys_get_temp_dir().'/report-page-number-test-cache';
    if (!is_dir($cache)) mkdir($cache, 0777, true);
    config()->set('view.compiled', $cache);
    config()->set('dompdf.options.font_cache', $cache);
    config()->set('excel.temporary_files.local_path', $cache);
});

function pageNumberResult(array $layout): ReportResult
{
    $definition = ReportDefinition::fromArray(['name' => 'Page layout QA', 'data_source' => 'test',
        'columns' => [['field' => 'label', 'label' => 'Detail']], 'layout' => $layout]);
    return new ReportResult(new Report(['name' => 'Page layout QA']), $definition, [],
        collect(range(1, 180))->map(fn ($i) => ['label' => "Detail row {$i}"]),
        [['field' => 'label', 'label' => 'Detail', 'type' => 'string']], layout: $layout);
}

it('persists and reloads independent page-number settings without losing legacy content', function () {
    $layout = ['headerText' => 'Company', 'footerText' => "Confidential\nSecond line", 'footerStyle' => ['textColor' => '#ff0000'], 'showPageNumbers' => true,
        'pageNumbers' => ['position' => 'header', 'alignment' => 'center', 'fontSize' => 12, 'fontSizeUnit' => 'px', 'contentSpacing' => 0, 'edgeSpacing' => 30, 'textColor' => '#0000ff']];
    $report = Report::create(['name' => 'Page settings', 'code' => 'page_settings', 'data_source' => 'test',
        'definition' => ['name' => 'Page settings', 'data_source' => 'test', 'layout' => $layout]]);
    $report = $report->fresh();
    expect($report->definition['layout'])->toBe($layout)
        ->and(ReportDefinition::fromArray($report->definition)->toArray()['layout'])->toBe($layout);
    $definition = $report->definition;
    $definition['layout']['showPageNumbers'] = false;
    $report->update(['definition' => $definition]);
    expect($report->fresh()->definition['layout']['showPageNumbers'])->toBeFalse()
        ->and($report->fresh()->definition['layout']['pageNumbers'])->toBe($layout['pageNumbers']);
});

it('renders real multipage PDFs with reserved number regions', function ($paper, $orientation, $position, $enabled) {
    $this->app->register(\Barryvdh\DomPDF\ServiceProvider::class);
    $wrapper = app('dompdf.wrapper');
    $this->app->instance('dompdf.wrapper', $wrapper);
    config()->set('advanced-reports.pdf.driver', 'dompdf');
    $layout = ['pageSize' => $paper, 'orientation' => $orientation, 'footerStyle' => ['textColor' => '#ff0000'],
        'headerText' => "Company heading\nHeader line two", 'footerText' => "Confidential footer\nFooter line two\nFooter line three",
        'showPageNumbers' => $enabled, 'pageNumbers' => ['position' => $position, 'alignment' => 'center', 'contentSpacing' => 12, 'edgeSpacing' => 24, 'fontSize' => 11]];
    $response = app(PdfRenderer::class)->render(pageNumberResult($layout));
    expect($response->getContent())->toStartWith('%PDF-');
    $dompdf = $wrapper->getDomPDF();
    expect($dompdf->getCanvas()->get_page_count())->toBeGreaterThan(1);
    // Enable artifact output explicitly for visual QA, not normal test runs.
    if ($directory = getenv('REPORT_PAGE_QA_DIR')) {
        file_put_contents($directory."/{$paper}-{$orientation}-{$position}-".($enabled ? 'on' : 'off').'.pdf', $response->getContent());
        if ($paper === 'a4' && $orientation === 'portrait' && $position === 'footer' && $enabled) {
            file_put_contents($directory.'/red-footer-black-page-numbers.pdf', $response->getContent());
        }
    }
})->with(['a4', 'letter', 'legal'])->with(['portrait', 'landscape'])->with(['header', 'footer'])->with([true, false]);

it('writes Excel print sections, font size, position, and margins into a loadable XLSX', function ($position, $alignment, $enabled) {
    $this->app->register(\Maatwebsite\Excel\ExcelServiceProvider::class);
    $layout = ['headerText' => "Company\nHeader second line", 'footerText' => "Confidential\nFooter second line", 'footerStyle' => ['textColor' => '#ff0000'],
        'showPageNumbers' => $enabled, 'pageNumbers' => ['position' => $position, 'alignment' => $alignment, 'fontSize' => 12, 'edgeSpacing' => 30, 'contentSpacing' => 10]];
    $excel = app(ExcelRenderer::class);
    (new ReflectionProperty($excel, 'result'))->setValue($excel, pageNumberResult($layout));
    $bytes = \Maatwebsite\Excel\Facades\Excel::raw($excel, \Maatwebsite\Excel\Excel::XLSX);
    $path = tempnam(sys_get_temp_dir(), 'page-number-xlsx-');
    file_put_contents($path, $bytes);
    try {
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $book->getActiveSheet();
        $areas = $sheet->getHeaderFooter();
        $selected = $position === 'header' ? $areas->getOddHeader() : $areas->getOddFooter();
        expect($areas->getOddHeader())->toContain('Company')
            ->and($areas->getOddFooter())->toContain('Confidential');
        expect($areas->getOddFooter())->toContain('&KFF0000');
        if ($enabled) {
            expect($selected)->toContain('&12')->toContain('Page &P of &N')->toContain("\n");
            expect($selected)->toContain('&K000000');
            $margin = $position === 'header' ? $sheet->getPageMargins()->getHeader() : $sheet->getPageMargins()->getFooter();
            expect($margin)->toEqualWithDelta(30 / 72, 0.001);
        } else {
            expect($selected)->not->toContain('Page &P');
        }
        $book->disconnectWorksheets();
    } finally { @unlink($path); }
})->with(['header', 'footer'])->with(['left', 'center', 'right'])->with([true, false]);

it('renders large multiline inline footers in every independent alignment', function ($orientation, $footerAlignment, $numberAlignment) {
    $this->app->register(\Barryvdh\DomPDF\ServiceProvider::class);
    $wrapper = app('dompdf.wrapper');
    $this->app->instance('dompdf.wrapper', $wrapper);
    config()->set('advanced-reports.pdf.driver', 'dompdf');
    $layout = ['orientation' => $orientation, 'headerText' => 'Company heading',
        'footerText' => "Confidential information wraps safely inside the remaining footer region.\nSecond footer paragraph.",
        'footerStyle' => ['fontSize' => 24, 'alignment' => $footerAlignment, 'padding' => 8,
            'borderStyle' => 'solid', 'borderWidth' => 2, 'backgroundColor' => '#eeeeff'],
        'showPageNumbers' => true, 'pageNumbers' => ['alignment' => $numberAlignment, 'fontSize' => 24, 'contentSpacing' => 12, 'edgeSpacing' => 24]];
    $response = app(PdfRenderer::class)->render(pageNumberResult($layout));
    expect($response->getContent())->toStartWith('%PDF-')
        ->and($wrapper->getDomPDF()->getCanvas()->get_page_count())->toBeGreaterThan(1);
    if ($directory = getenv('REPORT_PAGE_QA_DIR')) {
        file_put_contents($directory."/inline-{$orientation}-{$footerAlignment}-{$numberAlignment}.pdf", $response->getContent());
    }
})->with(['portrait', 'landscape'])->with(['left', 'center', 'right'])->with(['left', 'center', 'right']);

it('renders an explicitly configured page-number color independently from footer styling', function () {
    $this->app->register(\Barryvdh\DomPDF\ServiceProvider::class);
    $wrapper = app('dompdf.wrapper');
    $this->app->instance('dompdf.wrapper', $wrapper);
    config()->set('advanced-reports.pdf.driver', 'dompdf');
    $layout = ['footerText' => 'Red footer content', 'footerStyle' => ['textColor' => '#ff0000'],
        'showPageNumbers' => true, 'pageNumbers' => ['textColor' => '#0000ff']];
    $response = app(PdfRenderer::class)->render(pageNumberResult($layout));
    expect($response->getContent())->toStartWith('%PDF-')
        ->and($wrapper->getDomPDF()->getCanvas()->get_page_count())->toBeGreaterThan(1)
        ->and(\ElgiborSolution\AdvancedReports\Support\PageNumberSettings::pdfRgb($layout))->toBe([0, 0, 1]);
    if ($directory = getenv('REPORT_PAGE_QA_DIR')) {
        file_put_contents($directory.'/red-footer-blue-page-numbers.pdf', $response->getContent());
    }
});

it('uses percentage widths for DomPDF fixed-layout footer cells rather than equal columns', function () {
    $layout = ['footerText' => 'Configured footer', 'showPageNumbers' => true];
    $html = app(\ElgiborSolution\AdvancedReports\Renderers\HtmlRenderer::class)->render(pageNumberResult($layout), ['pdf_mode' => true]);
    expect($html)->toContain('footer-content-region')->toContain('footer-number-region');
    preg_match_all('/class="footer-(?:content|number)-region" style="width: ([\d.]+)%"/', $html, $matches);
    expect($matches[1])->toHaveCount(2)->and((float) $matches[1][0])->toBeGreaterThan((float) $matches[1][1]);
});

it('keeps Excel footer counters in a different section even when alignments match', function ($alignment) {
    $excel = app(ExcelRenderer::class);
    $layout = ['footerText' => 'Configured footer', 'footerStyle' => ['alignment' => $alignment],
        'showPageNumbers' => true, 'pageNumbers' => ['alignment' => $alignment]];
    (new ReflectionProperty($excel, 'result'))->setValue($excel, pageNumberResult($layout));
    $sheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet();
    $excel->registerEvents()[\Maatwebsite\Excel\Events\AfterSheet::class](new \Maatwebsite\Excel\Events\AfterSheet(new \Maatwebsite\Excel\Sheet($sheet), $excel));
    $encoded = $sheet->getHeaderFooter()->getOddFooter();
    $sections = preg_split('/&[LCR]/', $encoded);
    foreach ($sections as $section) {
        expect(str_contains($section, 'Configured footer') && str_contains($section, 'Page &P'))->toBeFalse();
    }
    expect($encoded)->not->toContain("\n");
})->with(['left', 'center', 'right']);
