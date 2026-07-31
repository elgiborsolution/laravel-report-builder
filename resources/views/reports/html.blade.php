@php
/** @var \ElgiborSolution\AdvancedReports\Models\Report $report */
/** @var array $columns */
/** @var array $rows */
/** @var array $groups */
/** @var array $aggregates */
/** @var array $drilldownMap */
/** @var array $conditionalStyles */

$styleForCell = function (string $field, $value) use ($conditionalStyles) {
    if (! isset($conditionalStyles[$field])) {
        return '';
    }
    foreach ($conditionalStyles[$field] as $rule) {
        $match = match ($rule['operator']) {
            '>'     => $value > $rule['value'],
            '>='    => $value >= $rule['value'],
            '<'     => $value < $rule['value'],
            '<='    => $value <= $rule['value'],
            '='     => $value == $rule['value'],
            '!='    => $value != $rule['value'],
            default => false,
        };
        if ($match) {
            $css = collect($rule['style'] ?? [])->map(fn ($v, $k) => str_replace('_', '-', $k).': '.$v)->implode('; ');
            return $css ? "style=\"{$css}\"" : '';
        }
    }
    return '';
};

$drilldownLink = function (string $field, $value, $row) use ($drilldownMap) {
    if (! isset($drilldownMap[$field])) {
        return e((string) $value);
    }
    $dd = $drilldownMap[$field];
    $url = $dd['_meta']['url'] ?? null;
    if (! $url) {
        return e((string) $value);
    }
    return '<a href="'.e($url).'" data-drilldown="'.e($field).'">'.e((string) $value).'</a>';
};
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $report->name }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 24px; color: #1f2937; }
        h1 { font-size: 1.5rem; margin-bottom: 4px; }
        .meta { color: #6b7280; font-size: 0.85rem; margin-bottom: 16px; }
        table { border-collapse: collapse; width: 100%; font-size: 0.9rem; }
        th, td { border: 1px solid #e5e7eb; padding: 8px 10px; text-align: left; }
        thead th { background: #f3f4f6; font-weight: 600; position: sticky; top: 0; }
        tbody tr:nth-child(even) { background: #fafafa; }
        tfoot td { background: #eff6ff; font-weight: 600; }
        .group-header td { background: #e0e7ff; font-weight: 700; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.04em; }
        a[data-drilldown] { color: #2563eb; text-decoration: none; }
        a[data-drilldown]:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <h1>{{ $report->name }}</h1>
    <div class="meta">
        @if ($report->description)<p>{{ $report->description }}</p>@endif
        Generated {{ now()->toDateTimeString() }} &middot;
        {{ $metadata['row_count'] ?? count($rows) }} rows
    </div>

    <table>
        <thead>
            <tr>
                @foreach ($columns as $col)
                    <th>{{ $col['label'] ?? ucfirst($col['field'] ?? '') }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                @php
                    // Emit group header rows for the first group field when its value changes.
                    $showGroupHeader = false;
                    $groupField = array_key_first($groups) ?? null;
                @endphp

                <tr>
                    @foreach ($columns as $col)
                        @php
                            $field = $col['field'] ?? '';
                            $value = data_get($row, $field);
                            $styleAttr = $styleForCell($field, $value);
                        @endphp
                        <td {!! $styleAttr !!}>{!! $drilldownLink($field, $value, $row) !!}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>

        @if (! empty($aggregates))
        <tfoot>
            <tr>
                @php
                    $aggValues = array_values($aggregates);
                    $aggCount = count($aggValues);
                @endphp
                @foreach ($columns as $i => $col)
                    @php
                        $pad = count($columns) - $aggCount;
                        $value = $i >= $pad ? ($aggValues[$i - $pad] ?? '') : ($i === 0 ? 'Total' : '');
                    @endphp
                    <td>{{ is_scalar($value) ? $value : '' }}</td>
                @endforeach
            </tr>
        </tfoot>
        @endif
    </table>
</body>
</html>
