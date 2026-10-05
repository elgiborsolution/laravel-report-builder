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
        $layout = $result->layout ?? [];
        $driver = config('advanced-reports.pdf.driver', 'dompdf');
        $options = [
            ...$options,
            'paper' => $options['paper'] ?? $layout['pageSize'] ?? config('advanced-reports.pdf.options.paper', 'a4'),
            'orientation' => $options['orientation'] ?? $layout['orientation'] ?? config('advanced-reports.pdf.options.orientation', 'portrait'),
            'pdf_mode' => true,
            'show_page_numbers' => (bool) ($layout['showPageNumbers'] ?? false),
        ];
        $options['paper'] = $this->normalizePaper((string) $options['paper']);
        $options['orientation'] = $this->normalizeOrientation((string) $options['orientation']);
        $html = $this->htmlRenderer->render($result, $options);

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

        if ($options['show_page_numbers'] ?? false) {
            $pdf->render();
            $dompdf = $pdf->getDomPDF();
            $canvas = $dompdf->getCanvas();
            $fontMetrics = $dompdf->getFontMetrics();
            $font = $fontMetrics->getFont('Helvetica', 'normal');
            $label = 'Page {PAGE_NUM} of {PAGE_COUNT}';
            $labelWidth = $fontMetrics->getTextWidth('Page 999 of 999', $font, 8);

            $canvas->page_text(
                $canvas->get_width() - 40 - $labelWidth,
                $canvas->get_height() - 28,
                $label,
                $font,
                8,
                [0.29, 0.33, 0.38],
            );
        }

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

        $browser = \Spatie\Browsershot\Browsershot::html($html)
            ->paperSize(ucfirst((string) ($options['paper'] ?? config('advanced-reports.pdf.options.paper', 'a4'))));

        if (($options['orientation'] ?? 'portrait') === 'landscape') {
            $browser->landscape();
        }

        return $browser->pdf();
    }

    protected function renderWithCustom(string $html, array $options = []): mixed
    {
        $engine = null;
        foreach (app()->tagged('advanced-reports.pdf.engine') as $taggedEngine) {
            $engine = $taggedEngine;
            break;
        }
        if (! $engine || ! is_callable($engine)) {
            throw MissingDependencyException::forPackage('custom PDF engine', 'PDF export');
        }

        return $engine($html, $options);
    }

    protected function filename(array $options = []): string
    {
        return ($options['filename'] ?? 'report') . '_' . now()->format('Y-m-d_His');
    }

    protected function normalizePaper(string $paper): string
    {
        $paper = strtolower($paper);

        return in_array($paper, ['a4', 'letter', 'legal'], true)
            ? $paper
            : strtolower((string) config('advanced-reports.pdf.options.paper', 'a4'));
    }

    protected function normalizeOrientation(string $orientation): string
    {
        $orientation = strtolower($orientation);

        return in_array($orientation, ['portrait', 'landscape'], true)
            ? $orientation
            : strtolower((string) config('advanced-reports.pdf.options.orientation', 'portrait'));
    }
}
