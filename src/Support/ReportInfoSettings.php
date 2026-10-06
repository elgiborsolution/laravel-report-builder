<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Support;

use ElgiborSolution\AdvancedReports\Engine\ReportResult;

/**
 * Resolves the report-level information block shared by HTML/PDF and Excel.
 * Its style lives separately from page headers, footers, and page numbers.
 */
final class ReportInfoSettings
{
    private const DEFAULT_STYLE = [
        'alignment' => 'left',
        'fontFamily' => 'Arial',
        'fontSize' => 10,
        'fontSizeUnit' => 'pt',
        'bold' => false,
        'italic' => false,
        'underline' => false,
        'textColor' => '#4b5563',
        'backgroundColor' => null,
        'spacing' => 10,
    ];

    private const FONTS = ['Arial', 'Helvetica', 'Times New Roman', 'Courier New'];

    /** @return array<string,mixed> */
    public static function resolve(ReportResult $result): array
    {
        $layout = is_array($result->layout) ? $result->layout : [];
        $rawStyle = is_array($layout['reportInfoStyle'] ?? null) ? $layout['reportInfoStyle'] : null;
        $title = self::text($layout['title'] ?? null) ?: self::text($result->report->name ?? null);
        $subtitle = self::text($layout['subtitle'] ?? null);
        $description = self::text($layout['description'] ?? null) ?: self::text($result->report->description ?? null);
        $lines = array_values(array_filter([
            ['role' => 'title', 'text' => $title],
            ['role' => 'subtitle', 'text' => $subtitle],
            ['role' => 'description', 'text' => $description],
        ], static fn (array $line): bool => $line['text'] !== ''));

        return [
            'visible' => ($layout['showReportInfo'] ?? true) !== false,
            'style' => self::normalize($rawStyle),
            'title' => $title,
            'subtitle' => $subtitle,
            'description' => $description,
            'lines' => $lines,
        ];
    }

    /** Normalize user input before it is embedded in CSS or spreadsheet styles. */
    public static function normalize(?array $style): array
    {
        $style = array_replace(self::DEFAULT_STYLE, $style ?? []);
        $style['alignment'] = in_array($style['alignment'], ['left', 'center', 'right'], true)
            ? $style['alignment'] : self::DEFAULT_STYLE['alignment'];
        $style['fontFamily'] = in_array($style['fontFamily'], self::FONTS, true)
            ? $style['fontFamily'] : self::DEFAULT_STYLE['fontFamily'];
        $style['fontSizeUnit'] = in_array($style['fontSizeUnit'], ['pt', 'px'], true) ? $style['fontSizeUnit'] : 'pt';
        $style['fontSize'] = self::number($style['fontSize'], 10, 4, 24);
        $style['spacing'] = self::number($style['spacing'], 10, 0, 48);
        $style['bold'] = (bool) $style['bold'];
        $style['italic'] = (bool) $style['italic'];
        $style['underline'] = (bool) $style['underline'];
        $style['textColor'] = self::color($style['textColor'], '#4b5563');
        $style['backgroundColor'] = self::optionalColor($style['backgroundColor']);

        return $style;
    }

    /** CSS for a configured Report Info block; legacy blocks keep their historic role styling. */
    public static function css(?array $style): string
    {
        $style = self::normalize($style);
        $font = match ($style['fontFamily']) {
            'Helvetica' => 'Helvetica, Arial, sans-serif',
            'Times New Roman' => 'Times New Roman, serif',
            'Courier New' => 'Courier New, monospace',
            default => 'Arial, Helvetica, sans-serif',
        };

        return implode('; ', [
            'text-align: '.$style['alignment'],
            'font-family: '.$font,
            'font-size: '.$style['fontSize'].$style['fontSizeUnit'],
            'font-weight: '.($style['bold'] ? 'bold' : 'normal'),
            'font-style: '.($style['italic'] ? 'italic' : 'normal'),
            'text-decoration: '.($style['underline'] ? 'underline' : 'none'),
            'color: '.$style['textColor'],
            'background-color: '.($style['backgroundColor'] ?? 'transparent'),
            'margin-bottom: '.$style['spacing'].'pt',
            'box-sizing: border-box',
            'line-height: 1.35',
            'overflow-wrap: anywhere',
        ]);
    }

    public static function fontSizeInPoints(array $style): float
    {
        $style = self::normalize($style);

        return $style['fontSizeUnit'] === 'px' ? $style['fontSize'] * 0.75 : $style['fontSize'];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function number(mixed $value, float $default, float $min, float $max): float
    {
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            return $default;
        }

        return min($max, max($min, (float) $value));
    }

    private static function color(mixed $value, string $default): string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1
            ? strtolower($value)
            : $default;
    }

    private static function optionalColor(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === 'transparent') {
            return null;
        }

        return self::color($value, '#ffffff');
    }
}
