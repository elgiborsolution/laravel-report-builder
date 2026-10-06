<?php

declare(strict_types=1);

use ElgiborSolution\AdvancedReports\Definitions\ReportDefinition;
use ElgiborSolution\AdvancedReports\Engine\ReportResult;
use ElgiborSolution\AdvancedReports\Models\Report;
use ElgiborSolution\AdvancedReports\Renderers\HtmlRenderer;
use ElgiborSolution\AdvancedReports\Support\ReportInfoSettings;
use ElgiborSolution\AdvancedReports\Tests\TestCase;
use Illuminate\Support\Collection;

uses(TestCase::class);

function reportInfoResult(?array $layout = null): ReportResult
{
    $report = new Report;
    $report->name = 'Model Report Name';
    $report->description = 'Model description';

    return new ReportResult(
        $report,
        ReportDefinition::fromArray(['name' => 'Model Report Name', 'data_source' => 'test_source']),
        [],
        new Collection([['amount' => 12]]),
        [['field' => 'amount', 'label' => 'Amount']],
        layout: $layout,
        metadata: ['row_count' => 1],
    );
}

it('resolves compatible Report Info defaults and content from layout then report metadata', function () {
    $info = ReportInfoSettings::resolve(reportInfoResult());

    expect($info['visible'])->toBeTrue()
        ->and($info['title'])->toBe('Model Report Name')
        ->and($info['subtitle'])->toBe('')
        ->and($info['description'])->toBe('Model description')
        ->and($info)->not->toHaveKey('meta')
        ->and($info['style'])->toMatchArray(['alignment' => 'left', 'fontFamily' => 'Arial', 'fontSize' => 10.0, 'fontSizeUnit' => 'pt', 'bold' => false])
        ->and(array_column($info['lines'], 'role'))->toBe(['title', 'description']);
});

it('renders default Report Info and legacy report metadata through the shared HTML layout', function () {
    $html = app(HtmlRenderer::class)->render(reportInfoResult());

    expect($html)
        ->toContain('<section class="report-info"')
        ->toContain('font-size: 10pt')
        ->toContain('<h1>Model Report Name</h1>')
        ->toContain('<p class="report-info-description">Model description</p>')
        ->not->toContain('Generated ')
        ->not->toContain('1 rows')
        ->not->toContain('<div class="meta">');
});

it('omits an empty Report Info wrapper when no user content is configured', function () {
    $result = reportInfoResult(['title' => ' ', 'subtitle' => '', 'description' => ' ']);
    $result->report->name = '';
    $result->report->description = '';
    $html = app(HtmlRenderer::class)->render($result);

    expect($html)->not->toContain('class="report-info"')
        ->and($html)->toContain('<table');
});

it('omits the HTML Report Info block when visibility is disabled', function () {
    $html = app(HtmlRenderer::class)->render(reportInfoResult(['showReportInfo' => false]));

    expect($html)->not->toContain('<section class="report-info"')
        ->and($html)->toContain('<table');
});

it('normalizes independent Report Info presentation settings and preserves visibility choices', function () {
    $info = ReportInfoSettings::resolve(reportInfoResult([
        'title' => 'Layout title',
        'subtitle' => 'Layout subtitle',
        'description' => 'Layout description',
        'showReportInfo' => false,
        'reportInfoStyle' => [
            'alignment' => 'right', 'fontFamily' => 'Courier New', 'fontSize' => 16, 'fontSizeUnit' => 'px',
            'bold' => true, 'italic' => true, 'underline' => true, 'textColor' => '#123456',
            'backgroundColor' => '#fedcba', 'spacing' => 22,
        ],
        'headerStyle' => ['textColor' => '#ff0000'],
        'footerStyle' => ['textColor' => '#0000ff'],
        'pageNumbers' => ['textColor' => '#00ff00'],
    ]));

    expect($info['visible'])->toBeFalse()
        ->and($info['title'])->toBe('Layout title')
        ->and($info['subtitle'])->toBe('Layout subtitle')
        ->and($info['description'])->toBe('Layout description')
        ->and($info['style'])->toMatchArray([
            'alignment' => 'right', 'fontFamily' => 'Courier New', 'fontSize' => 16.0, 'fontSizeUnit' => 'px',
            'bold' => true, 'italic' => true, 'underline' => true, 'textColor' => '#123456',
            'backgroundColor' => '#fedcba', 'spacing' => 22.0,
        ])
        ->and(ReportInfoSettings::css($info['style']))
        ->toContain('text-align: right')
        ->toContain('font-size: 16px')
        ->toContain('text-decoration: underline')
        ->toContain('color: #123456')
        ->toContain('background-color: #fedcba')
        ->toContain('margin-bottom: 22pt');
});

it('falls back safely for invalid Report Info style values', function () {
    $style = ReportInfoSettings::normalize([
        'alignment' => 'justify', 'fontFamily' => 'Comic Sans', 'fontSize' => 200, 'fontSizeUnit' => 'em',
        'bold' => 'false', 'textColor' => 'red', 'backgroundColor' => 'invalid', 'spacing' => -5,
    ]);

    expect($style)->toMatchArray([
        'alignment' => 'left', 'fontFamily' => 'Arial', 'fontSize' => 24.0, 'fontSizeUnit' => 'pt',
        'bold' => true, 'textColor' => '#4b5563', 'backgroundColor' => '#ffffff', 'spacing' => 0.0,
    ]);
});
