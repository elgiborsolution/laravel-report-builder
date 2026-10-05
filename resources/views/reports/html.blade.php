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

$layout = is_array($layout ?? null) ? $layout : [];
$headerText = trim((string) ($layout['headerText'] ?? ''));
$footerText = trim((string) ($layout['footerText'] ?? ''));
$showPageNumbers = (bool) ($layout['showPageNumbers'] ?? false);
$showBorders = (bool) ($layout['showBorders'] ?? true);
$pdfMode = (bool) ($pdfMode ?? false);
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
        th, td { padding: 8px 10px; text-align: left; }
        .report-table.with-borders th, .report-table.with-borders td { border: 1px solid #e5e7eb; }
        .report-table.without-borders th, .report-table.without-borders td { border: none; }
        thead th { background: #f3f4f6; font-weight: 600; }
        tbody tr:nth-child(even) { background: #fafafa; }
        tfoot td { background: #eff6ff; font-weight: 600; }
        .group-header td { background: #e0e7ff; font-weight: 700; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.04em; }
        .group-subtotal td { background: #f3f4f6; font-weight: 600; }
        .group-subtotal-label td { background: #f3f4f6; font-weight: 600; }
        .grand-total td { background: #eff6ff; font-weight: 700; }
        .grand-total-label td { background: #eff6ff; font-weight: 700; }
        .aggregate-value + .aggregate-value { display: block; }
        a[data-drilldown] { color: #2563eb; text-decoration: none; }
        a[data-drilldown]:hover { text-decoration: underline; }
        .report-layout-header, .report-layout-footer { color: #4b5563; font-size: 9pt; }
        .report-layout-header { margin-bottom: 10px; }
        .report-layout-footer { display: flex; justify-content: space-between; margin-top: 10px; }
        @if ($pdfMode)
            @page { margin: 2cm 1.4cm; }
            body { margin: 0; }
            h1 { font-size: 14pt; }
            .report-layout-header { position: fixed; top: -1.35cm; left: 0; right: 0; height: .65cm; margin: 0; border-bottom: .5pt solid #d1d5db; }
            .report-layout-footer { position: fixed; bottom: -1.35cm; left: 0; right: 0; height: .65cm; margin: 0; border-top: .5pt solid #d1d5db; }
            .report-page-number { margin-left: auto; text-align: right; }
            thead { display: table-header-group; }
            tfoot { display: table-row-group; }
            tr { page-break-inside: avoid; }
        @endif
    </style>
</head>
<body>
    @if ($headerText !== '')
        <div class="report-layout-header">{{ $headerText }}</div>
    @endif

    <h1>{{ $report->name }}</h1>
    <div class="meta">
        @if ($report->description)<p>{{ $report->description }}</p>@endif
        Generated {{ now()->toDateTimeString() }} &middot;
        {{ $metadata['row_count'] ?? count($rows) }} rows
    </div>

    <table class="report-table {{ $showBorders ? 'with-borders' : 'without-borders' }}">
        <thead>
            <tr>
                @foreach ($columns as $col)
                    <th>{{ $col['label'] ?? ucfirst($col['field'] ?? '') }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($presentationRows as $presentationRow)
                @if (($presentationRow['type'] ?? null) === 'group_header')
                    <tr class="group-header" data-group-level="{{ $presentationRow['level'] ?? 0 }}">
                        <td colspan="{{ max(1, count($columns)) }}" style="padding-left: {{ 10 + (($presentationRow['level'] ?? 0) * 20) }}px">
                            {{ $presentationRow['label'] ?? 'Group' }}: {{ $presentationRow['display_value'] ?? '(blank)' }}
                        </td>
                    </tr>
                @elseif (($presentationRow['type'] ?? null) === 'detail')
                    @php $row = $presentationRow['row'] ?? []; @endphp
                    <tr class="detail-row">
                        @foreach ($columns as $col)
                            @php
                                $field = $col['field'] ?? '';
                                $value = data_get($row, $field);
                                $styleAttr = $styleForCell($field, $value);
                            @endphp
                            <td {!! $styleAttr !!}>{!! $drilldownLink($field, $value, $row) !!}</td>
                        @endforeach
                    </tr>
                @elseif (($presentationRow['type'] ?? null) === 'group_subtotal' && isset($presentationRow['summary']))
                    @include('advanced-reports::reports.partials.summary-row', [
                        'summaryRow' => $presentationRow,
                        'rowClass' => 'group-subtotal',
                        'columnCount' => count($columns),
                    ])
                @endif
            @endforeach
        </tbody>

        @php $grandTotalRows = array_filter($presentationRows, static fn ($row) => ($row['type'] ?? null) === 'grand_total' && isset($row['summary'])); @endphp
        @if ($grandTotalRows)
            <tfoot>
                @foreach ($grandTotalRows as $grandTotal)
                    @include('advanced-reports::reports.partials.summary-row', [
                        'summaryRow' => $grandTotal,
                        'rowClass' => 'grand-total',
                        'columnCount' => count($columns),
                    ])
                @endforeach
            </tfoot>
        @endif
    </table>

    @if ($footerText !== '' || ($pdfMode && $showPageNumbers))
        <div class="report-layout-footer">
            @if ($footerText !== '')
                <span>{{ $footerText }}</span>
            @endif
            @if ($pdfMode && $showPageNumbers)
                <span class="report-page-number"></span>
            @endif
        </div>
    @endif
</body>
</html>
