<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Support;

/**
 * Normalizes report-level page decoration options before passing them to HTML,
 * PDF, or spreadsheet header/footer rendering. Only a small font/unit/color
 * allowlist is accepted because these values eventually become CSS or Excel
 * control codes.
 */
final class HeaderFooterStyle
{
    private const DEFAULTS = [
        'alignment' => 'left',
        'fontFamily' => 'Arial',
        'fontSize' => 9,
        'fontSizeUnit' => 'pt',
        'bold' => false,
        'italic' => false,
        'underline' => false,
        'textColor' => '#4b5563',
        'backgroundColor' => null,
        'padding' => 0,
        'paddingUnit' => 'pt',
        'borderStyle' => 'solid',
        'borderColor' => '#d1d5db',
        'borderWidth' => 0.5,
        'borderWidthUnit' => 'pt',
    ];

    private const FONTS = ['Arial', 'Helvetica', 'Times New Roman', 'Courier New'];

    public static function normalize(?array $style): array
    {
        $style = array_replace(self::DEFAULTS, $style ?? []);
        $style['alignment'] = in_array($style['alignment'], ['left', 'center', 'right'], true)
            ? $style['alignment']
            : self::DEFAULTS['alignment'];
        $style['fontFamily'] = in_array($style['fontFamily'], self::FONTS, true)
            ? $style['fontFamily']
            : self::DEFAULTS['fontFamily'];
        $style['fontSizeUnit'] = self::unit($style['fontSizeUnit']);
        $style['paddingUnit'] = self::unit($style['paddingUnit']);
        $style['borderWidthUnit'] = self::unit($style['borderWidthUnit']);
        $style['fontSize'] = self::number($style['fontSize'], 9, 4, 24);
        $style['padding'] = self::number($style['padding'], 0, 0, 16);
        $style['borderWidth'] = self::number($style['borderWidth'], 0.5, 0, 4);
        $style['bold'] = (bool) $style['bold'];
        $style['italic'] = (bool) $style['italic'];
        $style['underline'] = (bool) $style['underline'];
        $style['textColor'] = self::color($style['textColor'], '#4b5563');
        $style['backgroundColor'] = self::optionalColor($style['backgroundColor']);
        $style['borderColor'] = self::color($style['borderColor'], '#d1d5db');
        $style['borderStyle'] = in_array($style['borderStyle'], ['none', 'solid', 'dashed', 'dotted', 'double'], true)
            ? $style['borderStyle']
            : 'none';

        return $style;
    }

    /** Inline CSS supported by the report HTML and PDF layouts. */
    public static function css(?array $style): string
    {
        $style = self::normalize($style);
        $font = match ($style['fontFamily']) {
            'Helvetica' => 'Helvetica, Arial, sans-serif',
            'Times New Roman' => 'Times New Roman, serif',
            'Courier New' => 'Courier New, monospace',
            default => 'Arial, Helvetica, sans-serif',
        };
        $border = $style['borderStyle'] === 'none'
            ? 'none'
            : $style['borderWidth'].$style['borderWidthUnit'].' '.$style['borderStyle'].' '.$style['borderColor'];

        return implode('; ', [
            'text-align: '.$style['alignment'],
            'font-family: '.$font,
            'font-size: '.$style['fontSize'].$style['fontSizeUnit'],
            'font-weight: '.($style['bold'] ? 'bold' : 'normal'),
            'font-style: '.($style['italic'] ? 'italic' : 'normal'),
            'text-decoration: '.($style['underline'] ? 'underline' : 'none'),
            'color: '.$style['textColor'],
            'background-color: '.($style['backgroundColor'] ?? 'transparent'),
            'padding: '.$style['padding'].$style['paddingUnit'],
            'border: '.$border,
            'box-sizing: border-box',
        ]);
    }

    /** Convert configured font size to the points required by Excel headers. */
    public static function fontSizeInPoints(?array $style): float
    {
        $style = self::normalize($style);

        return $style['fontSizeUnit'] === 'px'
            ? $style['fontSize'] * 0.75
            : $style['fontSize'];
    }

    public static function paddingInPoints(?array $style): float
    {
        $style = self::normalize($style);

        return $style['paddingUnit'] === 'px'
            ? $style['padding'] * 0.75
            : $style['padding'];
    }

    /** Excel's page-header/footer format codes for an escaped text value. */
    public static function excelText(?array $style, string $text): string
    {
        $style = self::normalize($style);
        $fontStyle = match (true) {
            $style['bold'] && $style['italic'] => 'Bold Italic',
            $style['bold'] => 'Bold',
            $style['italic'] => 'Italic',
            default => 'Regular',
        };
        $size = max(1, (int) round(self::fontSizeInPoints($style)));
        $codes = '&"'.$style['fontFamily'].','.$fontStyle.'"&'.$size;
        if ($style['underline']) {
            $codes .= '&U';
        }
        $codes .= '&K'.strtoupper(substr($style['textColor'], 1));

        return $codes.str_replace('&', '&&', $text);
    }

    /** Combine rendered left, center, and right sections into Excel's format string. */
    public static function excelSections(array $sections): string
    {
        return '&L'.($sections['left'] ?? '').'&C'.($sections['center'] ?? '').'&R'.($sections['right'] ?? '');
    }

    public static function pdfFontFamily(?array $style): string
    {
        return match (self::normalize($style)['fontFamily']) {
            'Times New Roman' => 'Times-Roman',
            'Courier New' => 'Courier',
            default => 'Helvetica',
        };
    }

    public static function pdfFontStyle(?array $style): string
    {
        $style = self::normalize($style);

        return match (true) {
            $style['bold'] && $style['italic'] => 'bold_italic',
            $style['bold'] => 'bold',
            $style['italic'] => 'italic',
            default => 'normal',
        };
    }

    /** @return array{float,float,float} */
    public static function pdfRgb(?array $style): array
    {
        $hex = substr(self::normalize($style)['textColor'], 1);

        return [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];
    }

    private static function unit(mixed $unit): string
    {
        return in_array($unit, ['pt', 'px'], true) ? $unit : 'pt';
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
