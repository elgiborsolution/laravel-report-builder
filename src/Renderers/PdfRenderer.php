<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Renderers;

use ElgiborSolution\AdvancedReports\Contracts\ReportRenderer;
use ElgiborSolution\AdvancedReports\Engine\ReportResult;
use ElgiborSolution\AdvancedReports\Exceptions\MissingDependencyException;
use ElgiborSolution\AdvancedReports\Support\HeaderFooterStyle;
use ElgiborSolution\AdvancedReports\Support\PageNumberSettings;

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
        $options['page_numbers'] = PageNumberSettings::normalize($layout);
        $options['number_style'] = PageNumberSettings::style($layout);
        $measure = null;
        if ($driver === 'dompdf' && class_exists(\Barryvdh\DomPDF\PDF::class)) {
            $metrics = app('dompdf.wrapper')->getDomPDF()->getFontMetrics();
            $measure = static function (string $text, array $style, float $size) use ($metrics): float {
                $font = $metrics->getFont(HeaderFooterStyle::pdfFontFamily($style), HeaderFooterStyle::pdfFontStyle($style));
                return $metrics->getTextWidth($text, $font, $size);
            };
        }
        $options['page_geometry'] = PageNumberSettings::geometry($layout, $options['paper'], $options['orientation'], $measure);
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
            $style = $options['number_style'];
            $numberColor = HeaderFooterStyle::pdfRgb($style);
            $settings = $options['page_numbers'];
            $font = $fontMetrics->getFont(
                HeaderFooterStyle::pdfFontFamily($style),
                HeaderFooterStyle::pdfFontStyle($style),
            );
            $fontSize = PageNumberSettings::fontSize($settings);
            $geometry = $options['page_geometry'];
            $canvas->page_script(static function (int $page, int $count, $canvas, $metrics) use ($settings, $style, $font, $fontSize, $numberColor, $geometry): void {
                $label = "Page {$page} of {$count}";
                $labelWidth = $metrics->getTextWidth($label, $font, $fontSize);
                $inline = $geometry['footer']['inline'] && $settings['position'] === 'footer';
                $region = $geometry['footer'];
                $inset = $inline ? $geometry['side'] + $region['numberStart'] : max(40, $settings['edgeSpacing']);
                $regionWidth = $inline ? $region['numberWidth'] : $canvas->get_width() - 2 * $inset;
                if ($inline && $labelWidth > $regionWidth) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['definition.layout.pageNumbers' => 'Page counter exceeds the reserved footer region.']);
                }
                $x = match ($settings['alignment']) {
                    'left' => $inset, 'center' => $inset + ($regionWidth - $labelWidth) / 2,
                    default => $inset + $regionWidth - $labelWidth,
                };
                $y = $inline
                    ? $canvas->get_height() - $region['edge'] - $region['rowHeight'] + ($region['rowHeight'] - $region['numberHeight']) / 2
                    : $settings['edgeSpacing'];
                $canvas->text($x, $y, $label, $font, $fontSize, $numberColor);
            });
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
