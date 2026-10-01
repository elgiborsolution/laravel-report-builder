<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Support;

/**
 * Resolves how a subtotal/grand-total presentation row is laid out across
 * the visible report columns.
 *
 * Presentation is owned by each aggregate (aggregates[].output) and applies
 * wherever that aggregate's result appears: every group subtotal and the
 * grand total. Grouping only decides the calculation scope.
 *
 *   output: {
 *     column: field | "@last" | null      value column (null = own field)
 *     align, format, style: {...}         value cell presentation
 *     label: { show, text, column, colspan, align }
 *   }
 *
 * Aggregates without `output` are migrated from the earlier settings
 * (layout.summary.{subtotal,grand_total} and aggregates[].summary_columns) so
 * existing reports render as before: values under their own field and the
 * row name ("Subtotal" / "Grand total") in the first free column.
 *
 * The resolved segments cover each column exactly once, so renderers never
 * emit overlapping cells or a colspan past the table edge. The Angular
 * designer mirrors these rules in report-summary-layout.ts.
 */
final class SummaryRowLayout
{
    public const ROW_TYPES = ['group_subtotal' => 'subtotal', 'grand_total' => 'grand_total'];

    public const LABELS = ['group_subtotal' => 'Subtotal', 'grand_total' => 'Grand total'];

    public const ALIGNMENTS = ['left', 'center', 'right'];

    public const BORDER_STYLES = ['none', 'thin', 'thick', 'double'];

    public const BORDER_POSITIONS = ['all', 'top', 'bottom', 'top_bottom'];

    /** Value formats every renderer (Formatter and the Angular viewer) supports. */
    public const FORMATS = ['integer', 'decimal', 'currency', 'percentage'];

    public const MAX_COLSPAN = 100;

    public const MAX_LABEL_LENGTH = 100;

    /** Column reference meaning "the rightmost displayed column", whatever it is. */
    public const LAST_COLUMN = '@last';

    private const HEX_COLOR = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';

    /**
     * Normalized output settings for every aggregate, by definition index.
     *
     * @param  array<int,mixed>  $aggregates
     * @return array<int,array<string,mixed>>
     */
    public static function outputs(array $aggregates, ?array $layout): array
    {
        $aggregates = array_values($aggregates);
        $hasExplicit = false;
        foreach ($aggregates as $aggregate) {
            if (is_array($aggregate) && array_key_exists('output', $aggregate)) {
                $hasExplicit = true;
                break;
            }
        }

        // Earlier reports showed one row label; it moves to the first aggregate.
        $legacyLabelIndex = null;
        if (! $hasExplicit) {
            foreach ($aggregates as $index => $aggregate) {
                if (is_array($aggregate) && ! empty($aggregate['field'])) {
                    $legacyLabelIndex = $index;
                    break;
                }
            }
        }

        $outputs = [];
        foreach ($aggregates as $index => $aggregate) {
            $aggregate = is_array($aggregate) ? $aggregate : [];
            $outputs[$index] = array_key_exists('output', $aggregate)
                ? self::output($aggregate['output'])
                : self::legacyOutput($aggregate, $layout, $index === $legacyLabelIndex);
        }

        return $outputs;
    }

    /**
     * Normalize stored output settings. Invalid values are dropped rather than
     * trusted, because they end up in CSS and XLSX styles.
     *
     * @return array{column:?string,align:?string,format:?string,style:array<string,mixed>,label:array<string,mixed>}
     */
    public static function output(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $label = is_array($raw['label'] ?? null) ? $raw['label'] : [];
        $colspan = filter_var($label['colspan'] ?? 1, FILTER_VALIDATE_INT);
        $text = is_string($label['text'] ?? null) ? trim($label['text']) : '';

        return [
            'column' => self::columnRef($raw['column'] ?? null),
            'align' => self::enum($raw['align'] ?? null, self::ALIGNMENTS),
            'format' => self::enum($raw['format'] ?? null, self::FORMATS),
            'style' => self::style(is_array($raw['style'] ?? null) ? $raw['style'] : []),
            'label' => [
                'show' => (bool) ($label['show'] ?? false),
                'text' => mb_substr($text, 0, self::MAX_LABEL_LENGTH),
                'column' => self::columnRef($label['column'] ?? null),
                'colspan' => $colspan === false ? 1 : max(1, min(self::MAX_COLSPAN, $colspan)),
                'align' => self::enum($label['align'] ?? null, self::ALIGNMENTS),
            ],
        ];
    }

    /** @return array<string,mixed> Cell style without alignment (alignment is per value/label). */
    public static function style(array $style): array
    {
        return [
            'bold' => array_key_exists('bold', $style) ? (bool) $style['bold'] : true,
            'text_color' => self::color($style['text_color'] ?? null),
            'background_color' => self::color($style['background_color'] ?? null),
            'border_style' => self::enum($style['border_style'] ?? null, self::BORDER_STYLES),
            'border_position' => self::enum($style['border_position'] ?? null, self::BORDER_POSITIONS) ?? 'all',
            'border_color' => self::color($style['border_color'] ?? null),
        ];
    }

    /**
     * Shape errors for stored settings, keyed by definition path. Column
     * references are deliberately not checked: a removed or hidden column
     * falls back to the default placement instead of failing.
     *
     * @return array<string,string>
     */
    public static function validationErrors(mixed $layout, mixed $aggregates): array
    {
        $errors = [];

        foreach (is_array($aggregates) ? array_values($aggregates) : [] as $index => $aggregate) {
            if (! is_array($aggregate) || ! array_key_exists('output', $aggregate) || $aggregate['output'] === null) {
                continue;
            }

            $path = "aggregates.{$index}.output";
            $output = $aggregate['output'];
            if (! is_array($output)) {
                $errors[$path] = 'Aggregate output settings must be an object.';
                continue;
            }

            self::checkColumnRef($errors, "{$path}.column", $output['column'] ?? null);
            self::checkEnum($errors, "{$path}.align", $output['align'] ?? null, self::ALIGNMENTS, 'Value alignment');
            self::checkEnum($errors, "{$path}.format", $output['format'] ?? null, self::FORMATS, 'Value format');
            self::checkStyle($errors, "{$path}.style", $output['style'] ?? null);

            $label = $output['label'] ?? null;
            if ($label === null) {
                continue;
            }
            if (! is_array($label)) {
                $errors["{$path}.label"] = 'Label settings must be an object.';
                continue;
            }
            if (isset($label['show']) && ! is_bool($label['show'])) {
                $errors["{$path}.label.show"] = 'Show label must be true or false.';
            }
            if (isset($label['text']) && (! is_string($label['text']) || mb_strlen($label['text']) > self::MAX_LABEL_LENGTH)) {
                $errors["{$path}.label.text"] = 'Label text must be text of at most '.self::MAX_LABEL_LENGTH.' characters.';
            }
            self::checkColumnRef($errors, "{$path}.label.column", $label['column'] ?? null);
            if (isset($label['colspan'])) {
                $colspan = filter_var($label['colspan'], FILTER_VALIDATE_INT);
                if ($colspan === false || $colspan < 1 || $colspan > self::MAX_COLSPAN) {
                    $errors["{$path}.label.colspan"] = 'Label colspan must be a whole number from 1 to '.self::MAX_COLSPAN.'.';
                }
            }
            self::checkEnum($errors, "{$path}.label.align", $label['align'] ?? null, self::ALIGNMENTS, 'Label alignment');
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $presentationRow  A group_subtotal or grand_total event.
     * @param  array<int,array<string,mixed>>  $columns  Display columns, in order.
     * @param  array<int,array<string,mixed>>  $aggregateDefinitions
     * @param  callable(array $aggregate, ?string $format):mixed  $formatValue  Receives the effective format.
     * @return array{
     *     label_row:?array{text:string,style:array<string,mixed>,align:?string},
     *     segments:array<int,array<string,mixed>>
     * }
     */
    public static function resolve(
        array $presentationRow,
        array $columns,
        array $aggregateDefinitions,
        ?array $layout,
        callable $formatValue,
    ): array {
        $rowLabel = self::LABELS[$presentationRow['type'] ?? ''] ?? 'Subtotal';
        $outputs = self::outputs($aggregateDefinitions, $layout);
        $columns = array_values($columns);
        $columnCount = count($columns);

        $fieldIndexes = [];
        $formats = [];
        foreach ($columns as $index => $column) {
            $field = (string) ($column['field'] ?? $column['name'] ?? '');
            $fieldIndexes[$field] ??= $index;
            $formats[$field] ??= $column['format'] ?? $column['type'] ?? null;
        }
        $target = static fn (?string $ref): ?int => match (true) {
            $ref === self::LAST_COLUMN => $columnCount > 0 ? $columnCount - 1 : null,
            $ref !== null => $fieldIndexes[$ref] ?? null,
            default => null,
        };

        // Values: configured column, else the aggregate's own field column.
        $valuesByColumn = [];
        $present = [];
        foreach ((array) ($presentationRow['aggregate_cells'] ?? []) as $field => $cells) {
            foreach ((array) $cells as $cell) {
                if (! is_array($cell)) {
                    continue;
                }

                $index = isset($cell['index']) && isset($outputs[(int) $cell['index']]) ? (int) $cell['index'] : null;
                $output = $index !== null ? $outputs[$index] : self::output(null);
                if ($index !== null) {
                    $present[$index] = true;
                }

                $columnIndex = $target($output['column']) ?? $fieldIndexes[(string) $field] ?? null;
                if ($columnIndex === null) {
                    continue;
                }

                $format = $output['format'] ?? self::aggregateFormat($cell, $formats[(string) $field] ?? null);
                $valuesByColumn[$columnIndex][] = [
                    'index' => $index,
                    'label' => $cell['label'] ?? $cell['function'] ?? 'Aggregate',
                    'function' => $cell['function'] ?? null,
                    'format' => $format,
                    'value' => $formatValue($cell, $format),
                    'output' => $output,
                ];
            }
        }
        ksort($valuesByColumn);
        ksort($present);

        // Labels in aggregate order, each only across columns still free.
        $taken = [];
        $isFree = static fn (int $index): bool => ! isset($valuesByColumn[$index]) && ! isset($taken[$index]);
        $labels = [];
        $unplaced = [];
        foreach (array_keys($present) as $index) {
            $output = $outputs[$index];
            if (! $output['label']['show']) {
                continue;
            }

            $text = $output['label']['text'] !== '' ? $output['label']['text'] : $rowLabel;
            $start = $target($output['label']['column']);
            if ($start === null || ! $isFree($start)) {
                $start = null;
                for ($candidate = 0; $candidate < $columnCount; $candidate++) {
                    if ($isFree($candidate)) {
                        $start = $candidate;
                        break;
                    }
                }
            }
            if ($start === null) {
                $unplaced[] = ['text' => $text, 'output' => $output];
                continue;
            }

            $span = 1;
            while ($span < $output['label']['colspan'] && $start + $span < $columnCount && $isFree($start + $span)) {
                $span++;
            }
            for ($covered = $start; $covered < $start + $span; $covered++) {
                $taken[$covered] = true;
            }
            $labels[$start] = ['span' => $span, 'text' => $text, 'index' => $index, 'output' => $output];
        }

        $segments = [];
        for ($index = 0; $index < $columnCount;) {
            if (isset($labels[$index])) {
                $label = $labels[$index];
                $segments[] = [
                    'start' => $index,
                    'span' => $label['span'],
                    'kind' => 'label',
                    'index' => $label['index'],
                    'text' => $label['text'],
                    'values' => [],
                    'style' => $label['output']['style'],
                    'align' => $label['output']['label']['align'] ?? 'left',
                ];
                $index += $label['span'];
                continue;
            }

            $values = $valuesByColumn[$index] ?? [];
            // A column shared by several values takes the first one's presentation.
            $owner = $values[0]['output'] ?? null;
            $segments[] = [
                'start' => $index,
                'span' => 1,
                'kind' => $values === [] ? 'empty' : 'value',
                'index' => $values[0]['index'] ?? null,
                'text' => self::joinValues($values),
                'values' => $values,
                'style' => $owner['style'] ?? null,
                'align' => $owner ? ($owner['align'] ?? $columns[$index]['alignment'] ?? null) : null,
            ];
            $index++;
        }

        $labelRow = null;
        if ($unplaced !== [] && $columnCount > 0) {
            $labelRow = [
                'text' => implode(' · ', array_column($unplaced, 'text')),
                'style' => $unplaced[0]['output']['style'],
                'align' => $unplaced[0]['output']['label']['align'] ?? 'left',
            ];
        }

        return ['label_row' => $labelRow, 'segments' => $segments];
    }

    /** Count is always whole; averages of integers keep their decimals. */
    public static function aggregateFormat(array $aggregate, ?string $columnFormat): ?string
    {
        $function = strtolower((string) ($aggregate['function'] ?? ''));

        if ($function === 'count') {
            return 'integer';
        }

        if ($function === 'avg' && $columnFormat === 'integer') {
            return 'decimal';
        }

        return $columnFormat;
    }

    /** Multiple aggregates sharing a column keep the "Label: value | ..." form. */
    public static function joinValues(array $values): mixed
    {
        if ($values === []) {
            return '';
        }

        if (count($values) === 1) {
            return $values[0]['value'];
        }

        return implode(' | ', array_map(
            static fn (array $value) => $value['label'].': '.(is_scalar($value['value']) ? (string) $value['value'] : ''),
            $values,
        ));
    }

    /** Inline CSS for one summary cell; colors are already validated hex values. */
    public static function css(?array $style, ?string $align): string
    {
        $rules = [];
        if ($align) {
            $rules[] = "text-align: {$align}";
        }
        if ($style === null) {
            return implode('; ', $rules);
        }

        $rules[] = 'font-weight: '.(($style['bold'] ?? true) ? '700' : '400');
        if ($style['text_color'] ?? null) {
            $rules[] = "color: {$style['text_color']}";
        }
        if ($style['background_color'] ?? null) {
            $rules[] = "background: {$style['background_color']}";
        }

        $border = self::borderCss($style);
        if ($border !== null) {
            foreach (self::borderSides($style['border_position'] ?? 'all') as $side) {
                $rules[] = "border-{$side}: {$border}";
            }
        }

        return implode('; ', $rules);
    }

    /** @return array<int,string> */
    public static function borderSides(string $position): array
    {
        return match ($position) {
            'top' => ['top'],
            'bottom' => ['bottom'],
            'top_bottom' => ['top', 'bottom'],
            default => ['top', 'right', 'bottom', 'left'],
        };
    }

    /** Settings saved before outputs moved onto aggregates. */
    private static function legacyOutput(array $aggregate, ?array $layout, bool $showLabel): array
    {
        $summary = is_array($layout['summary'] ?? null) ? $layout['summary'] : [];
        $settings = is_array($summary['subtotal'] ?? null) ? $summary['subtotal']
            : (is_array($summary['grand_total'] ?? null) ? $summary['grand_total'] : []);
        $style = is_array($settings['style'] ?? null) ? $settings['style'] : [];
        $targets = is_array($aggregate['summary_columns'] ?? null) ? $aggregate['summary_columns'] : [];

        return self::output([
            'column' => $targets['subtotal'] ?? $targets['grand_total'] ?? null,
            'align' => $style['value_align'] ?? null,
            'style' => $style,
            'label' => [
                'show' => $showLabel,
                'column' => $settings['label_column'] ?? null,
                'colspan' => $settings['label_colspan'] ?? 1,
                'align' => $style['label_align'] ?? null,
            ],
        ]);
    }

    private static function borderCss(array $style): ?string
    {
        $color = ($style['border_color'] ?? null) ?: '#1f2937';

        return match ($style['border_style'] ?? null) {
            'none' => 'none',
            'thin' => "1px solid {$color}",
            'thick' => "2px solid {$color}",
            'double' => "3px double {$color}",
            default => null,
        };
    }

    private static function columnRef(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function enum(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    private static function color(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::HEX_COLOR, $value) ? $value : null;
    }

    private static function checkColumnRef(array &$errors, string $path, mixed $value): void
    {
        if ($value !== null && ! is_string($value)) {
            $errors[$path] = 'Column must be a column field name or "'.self::LAST_COLUMN.'".';
        }
    }

    private static function checkEnum(array &$errors, string $path, mixed $value, array $allowed, string $name): void
    {
        if ($value !== null && $value !== '' && ! in_array($value, $allowed, true)) {
            $errors[$path] = "{$name} must be one of: ".implode(', ', $allowed).'.';
        }
    }

    private static function checkStyle(array &$errors, string $path, mixed $style): void
    {
        if ($style === null) {
            return;
        }
        if (! is_array($style)) {
            $errors[$path] = 'Style must be an object.';
            return;
        }

        self::checkEnum($errors, "{$path}.border_style", $style['border_style'] ?? null, self::BORDER_STYLES, 'Border style');
        self::checkEnum($errors, "{$path}.border_position", $style['border_position'] ?? null, self::BORDER_POSITIONS, 'Border position');
        foreach (['text_color', 'background_color', 'border_color'] as $option) {
            $value = $style[$option] ?? null;
            if ($value !== null && $value !== '' && (! is_string($value) || ! preg_match(self::HEX_COLOR, $value))) {
                $errors["{$path}.{$option}"] = ucfirst(str_replace('_', ' ', $option)).' must be a hex color such as #1f2937.';
            }
        }
        if (isset($style['bold']) && ! is_bool($style['bold'])) {
            $errors["{$path}.bold"] = 'Bold must be true or false.';
        }
    }
}
