<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Support;

/** Saved page-number options and reserved page-decoration geometry (all distances in points). */
final class PageNumberSettings
{
    public static function normalize(array $layout): array
    {
        $config = is_array($layout['pageNumbers'] ?? null) ? $layout['pageNumbers'] : [];
        $legacyStyle = HeaderFooterStyle::normalize(is_array($layout['footerStyle'] ?? null) ? $layout['footerStyle'] : null);
        return [
            'enabled' => (bool) ($layout['showPageNumbers'] ?? false),
            'position' => in_array($config['position'] ?? null, ['header', 'footer'], true) ? $config['position'] : 'footer',
            'alignment' => in_array($config['alignment'] ?? null, ['left', 'center', 'right'], true)
                ? $config['alignment'] : ($legacyStyle['alignment'] === 'right' ? 'left' : 'right'),
            'fontSize' => self::number($config['fontSize'] ?? 9, 9, 4, 24),
            'fontSizeUnit' => ($config['fontSizeUnit'] ?? 'pt') === 'px' ? 'px' : 'pt',
            'textColor' => is_string($config['textColor'] ?? null) && preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $config['textColor'])
                ? (strlen($config['textColor']) === 4
                    ? '#'.$config['textColor'][1].$config['textColor'][1].$config['textColor'][2].$config['textColor'][2].$config['textColor'][3].$config['textColor'][3]
                    : strtoupper($config['textColor']))
                : '#000000',
            'contentSpacing' => self::number($config['contentSpacing'] ?? 8, 8, 0, 36),
            'edgeSpacing' => self::number($config['edgeSpacing'] ?? 18, 18, 8, 72),
        ];
    }

    public static function fontSize(array $settings): float
    {
        return $settings['fontSize'] * ($settings['fontSizeUnit'] === 'px' ? 0.75 : 1);
    }

    /** Resolve page-number typography with an independent color and legacy font settings. */
    public static function style(array $layout): array
    {
        $settings = self::normalize($layout);
        $style = $layout[$settings['position'].'Style'] ?? null;

        return HeaderFooterStyle::normalize([
            ...(is_array($style) ? $style : []),
            'textColor' => $settings['textColor'],
        ]);
    }

    /** @return array{float,float,float} */
    public static function pdfRgb(array $layout): array
    {
        return HeaderFooterStyle::pdfRgb(self::style($layout));
    }

    /** Word wrap using the same font metrics as the PDF engine; preserve explicit newlines. */
    public static function wrap(string $text, float $width, callable $measure): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $paragraph) {
            $line = '';
            foreach (preg_split('//u', $paragraph, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
                if ($line !== '' && $measure($line.$character) > $width) {
                    $space = mb_strrpos($line, ' ');
                    if ($space !== false && $space > 0) {
                        $lines[] = mb_substr($line, 0, $space);
                        $line = ltrim(mb_substr($line, $space + 1)).$character;
                    } else {
                        $lines[] = $line;
                        $line = $character;
                    }
                } else {
                    $line .= $character;
                }
            }
            $lines[] = $line;
        }
        return $lines;
    }

    public static function geometry(array $layout, string $paper, string $orientation, ?callable $measure = null): array
    {
        [$width, $height] = match ($paper) {
            'letter' => [612, 792], 'legal' => [612, 1008], default => [595.28, 841.89],
        };
        if ($orientation === 'landscape') [$width, $height] = [$height, $width];
        $settings = self::normalize($layout);
        $geometry = ['width' => $width, 'height' => $height, 'side' => 40.0];
        foreach (['header', 'footer'] as $target) {
            $text = trim((string) ($layout[$target.'Text'] ?? ''));
            $style = HeaderFooterStyle::normalize(is_array($layout[$target.'Style'] ?? null) ? $layout[$target.'Style'] : null);
            $size = HeaderFooterStyle::fontSizeInPoints($style);
            $padding = HeaderFooterStyle::paddingInPoints($style);
            $border = $style['borderStyle'] === 'none' ? 0 : $style['borderWidth'] * ($style['borderWidthUnit'] === 'px' ? 0.75 : 1);
            $inline = $target === 'footer' && $settings['enabled'] && $settings['position'] === 'footer';
            $numberStyle = [...$style, 'fontSize' => $settings['fontSize'], 'fontSizeUnit' => $settings['fontSizeUnit']];
            $numberSize = self::fontSize($settings);
            // Reserve up to six-digit page counters. The runtime label is drawn
            // ONLY within this cell, never independently over the content cell.
            $numberWidth = $inline ? ($text === '' ? $width - 80 : ($measure
                ? $measure('Page 999999 of 999999', $numberStyle, $numberSize)
                : mb_strlen('Page 999999 of 999999') * $numberSize * 0.65) + 8) : 0;
            $gap = $inline && $text !== '' ? $settings['contentSpacing'] : 0;
            $contentWidth = $width - 80 - $numberWidth - $gap;
            $textWidth = max(1, $contentWidth - 2 * ($padding + $border));
            $lines = $text === '' ? [] : self::wrap($text, $textWidth, fn (string $line) => $measure
                ? $measure($line, $style, $size) : mb_strlen($line) * $size);
            // Reserve a little more than the CSS line height for core-font metric
            // differences and rounding, even with contentSpacing set to zero.
            $textHeight = count($lines) * $size * 1.6 + ($text === '' ? 0 : 2 * ($padding + $border));
            $numberHeight = $settings['enabled'] && $settings['position'] === $target ? $numberSize * 1.4 : 0;
            $numberBand = $inline ? 0 : $numberHeight + ($numberHeight > 0 && $text !== '' ? $settings['contentSpacing'] : 0);
            $edge = $numberHeight > 0 ? $settings['edgeSpacing'] : 18;
            $rowHeight = $inline ? max($textHeight, $numberHeight) : $textHeight;
            $geometry[$target] = [
                'text' => implode("\n", $lines), 'textHeight' => $textHeight,
                'inline' => $inline, 'rowHeight' => $rowHeight,
                'contentWidth' => $contentWidth, 'numberWidth' => $numberWidth, 'gap' => $gap,
                'numberFirst' => $settings['alignment'] === 'left',
                'numberStart' => $settings['alignment'] === 'left' ? 0 : $contentWidth + $gap,
                'numberHeight' => $numberHeight, 'edge' => $edge,
                'margin' => max(40, $edge + $numberBand + $rowHeight + 12),
                'offset' => $edge + $numberBand,
            ];
        }
        if ($geometry['header']['margin'] + $geometry['footer']['margin'] > $height - 72) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'definition.layout' => 'Header/footer content and page-number spacing leave insufficient room for report data. Reduce their size or use a larger paper size.',
            ]);
        }
        return $geometry;
    }

    private static function number(mixed $value, float $default, float $min, float $max): float
    {
        return is_numeric($value) && is_finite((float) $value) ? min($max, max($min, (float) $value)) : $default;
    }
}
