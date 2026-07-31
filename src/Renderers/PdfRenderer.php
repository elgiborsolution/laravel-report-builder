<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Renderers;

use ElgiborSolution\AdvancedReports\Contracts\ReportRenderer;
use ElgiborSolution\AdvancedReports\Engine\ReportResult;
use ElgiborSolution\AdvancedReports\Exceptions\MissingDependencyException;

/**
 * PDF renderer. Renders HTML first, then converts to PDF via a configurable
 * engine (dompdf, browsershot, or custom).
 *
 * Architecture allows swapping the PDF driver without touching renderers.
 */
final class PdfRenderer implements ReportRenderer
{
    public function __construct(
        protected HtmlRenderer $htmlRenderer,
    ) {}

    public function format(): string
    {
        return 'pdf';
    }

    public function render(ReportResult $result, array $options = []): mixed
    {
        $html = $this->htmlRenderer->render($result, $options);
        $driver = config('advanced-reports.pdf.driver', 'dompdf');

        return match ($driver) {
            'dompdf' => $this->renderWithDompdf($html, $options),
            'browsershot' => $this->renderWithBrowsershot($html, $options),
            'custom' => $this->renderWithCustom($html, $options),
            default => throw MissingDependencyException::forPackage(
                "PDF driver [{$driver}]",
                'PDF rendering'
            ),
        };
    }

    protected function renderWithDompdf(string $html, array $options = []): mixed
    {
        if (! class_exists(\Barryvdh\DomPDF\PDF::class)) {
            throw MissingDependencyException::forPackage('barryvdh/laravel-dompdf', 'PDF export via DomPDF');
        }

        $paper = $options['paper'] ?? config('advanced-reports.pdf.options.paper', 'a4');
        $orientation = $options['orientation'] ?? config('advanced-reports.pdf.options.orientation', 'portrait');

        $pdf = app('dompdf.wrapper');
        $pdf->loadHTML($html)
            ->setPaper($paper, $orientation);

        // Allow callers to stream or download.
        if ($options['action'] ?? false === 'stream') {
            return $pdf->stream(
                $this->filename($options).'.pdf'
            );
        }

        return $pdf->download(
            $this->filename($options).'.pdf'
        );
    }

    protected function renderWithBrowsershot(string $html, array $options = []): mixed
    {
        if (! class_exists(\Spatie\Browsershot\Browsershot::class)) {
            throw MissingDependencyException::forPackage('spatie/browsershot', 'PDF export via Browsershot');
        }

        $paper = $options['paper'] ?? config('advanced-reports.pdf.options.paper', 'a4');

        return \Spatie\Browsershot\Browsershot::html($html)
            ->paperSize($paper)
            ->pdf();
    }

    protected function renderWithCustom(string $html, array $options = []): mixed
    {
        $engine = app()->tagged('advanced-reports.pdf.engine')[0] ?? null;
        if (! $engine || ! is_callable($engine)) {
            throw MissingDependencyException::forPackage('custom PDF engine', 'PDF export');
        }

        return $engine($html, $options);
    }

    protected function filename(array $options = []): string
    {
        return ($options['filename'] ?? 'report') . '_' . now()->format('Y-m-d_His');
    }
}
