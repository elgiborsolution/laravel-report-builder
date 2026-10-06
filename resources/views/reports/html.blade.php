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
$showBorders = (bool) ($layout['showBorders'] ?? true);
$pdfMode = (bool) ($pdfMode ?? false);
$headerStyle = \ElgiborSolution\AdvancedReports\Support\HeaderFooterStyle::normalize(is_array($layout['headerStyle'] ?? null) ? $layout['headerStyle'] : null);
$footerStyle = \ElgiborSolution\AdvancedReports\Support\HeaderFooterStyle::normalize(is_array($layout['footerStyle'] ?? null) ? $layout['footerStyle'] : null);
$headerStyleCss = \ElgiborSolution\AdvancedReports\Support\HeaderFooterStyle::css($headerStyle);
$footerStyleCss = \ElgiborSolution\AdvancedReports\Support\HeaderFooterStyle::css($footerStyle);
$reportInfo = is_array($reportInfo ?? null) ? $reportInfo : [
    'visible' => true,
    'title' => $report->name,
    'subtitle' => '',
    'description' => $report->description,
    'style' => \ElgiborSolution\AdvancedReports\Support\ReportInfoSettings::normalize(null),
];
$reportInfoHasContent = trim((string) ($reportInfo['title'] ?? '')) !== ''
    || trim((string) ($reportInfo['subtitle'] ?? '')) !== ''
    || trim((string) ($reportInfo['description'] ?? '')) !== '';
$reportInfoStyleCss = \ElgiborSolution\AdvancedReports\Support\ReportInfoSettings::css($reportInfo['style'] ?? null);
if ($pdfMode) {
    $pageGeometry ??= \ElgiborSolution\AdvancedReports\Support\PageNumberSettings::geometry($layout, $layout['pageSize'] ?? 'a4', $layout['orientation'] ?? 'portrait');
    $headerText = $pageGeometry['header']['text'];
    $footerText = $pageGeometry['footer']['text'];
    $headerStyleCss .= '; font-family: '.\ElgiborSolution\AdvancedReports\Support\HeaderFooterStyle::pdfFontFamily($headerStyle);
    $footerStyleCss .= '; font-family: '.\ElgiborSolution\AdvancedReports\Support\HeaderFooterStyle::pdfFontFamily($footerStyle);
}
$inlineFooter = $pdfMode && ($pageGeometry['footer']['inline'] ?? false);
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $report->name }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 24px; color: #1f2937; }
        h1 { font-size: 1.5rem; margin-bottom: 4px; }
        .report-info h1 { margin-top: 0; }
        .report-info p { margin-top: 0; margin-bottom: 0.25rem; }
        .report-info h1,
        .report-info p { font: inherit; text-align: inherit; color: inherit; text-decoration: inherit; margin: 0; background: transparent; }
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
        .report-layout-header, .report-layout-footer { color: #4b5563; white-space: pre-wrap; line-height: 1.4; overflow-wrap: break-word; }
        .report-layout-header { margin-bottom: 10px; }
        .report-layout-footer { display: block; margin-top: 10px; }
        .report-layout-decoration-table { width: 100%; table-layout: fixed; border-collapse: collapse; margin: 0; font: inherit; color: inherit; background: transparent; }
        .report-layout-decoration-table td { padding: 0; border: 0; background: transparent; vertical-align: middle; }
        @if ($pdfMode)
            @page { margin: {{ $pageGeometry['header']['margin'] }}pt {{ $pageGeometry['side'] }}pt {{ $pageGeometry['footer']['margin'] }}pt; }
            body { margin: 0; }
            h1 { font-size: 14pt; }
            .report-layout-header { position: fixed; top: {{ $pageGeometry['header']['offset'] - $pageGeometry['header']['margin'] }}pt; left: 0; right: 0; height: {{ $pageGeometry['header']['textHeight'] }}pt; margin: 0; }
            .report-layout-footer { position: fixed; bottom: {{ $pageGeometry['footer']['offset'] - $pageGeometry['footer']['margin'] }}pt; left: 0; right: 0; height: {{ $pageGeometry['footer']['rowHeight'] }}pt; margin: 0; }
            .footer-inline-table { table-layout: fixed; border-collapse: collapse; margin: 0; width: 100%; height: {{ $pageGeometry['footer']['rowHeight'] }}pt; white-space: normal; }
            .report-inline-footer { white-space: normal; }
            .footer-inline-table td { border: 0; padding: 0; background: transparent; vertical-align: middle; }
            thead { display: table-header-group; }
            tfoot { display: table-row-group; }
            tr { page-break-inside: avoid; }
        @endif
    </style>
</head>
<body>
    @if ($headerText !== '')
        <div class="report-layout-header" style="{{ $headerStyleCss }}">{{ $headerText }}</div>
    @endif

    @if ($reportInfo['visible'] && $reportInfoHasContent)
        <section class="report-info" style="{{ $reportInfoStyleCss }}">
            @if ($reportInfo['title'] !== '')<h1>{{ $reportInfo['title'] }}</h1>@endif
            @if ($reportInfo['subtitle'] !== '')<p class="report-info-subtitle">{{ $reportInfo['subtitle'] }}</p>@endif
            @if ($reportInfo['description'] !== '')<p class="report-info-description">{{ $reportInfo['description'] }}</p>@endif
        </section>
    @endif

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

    @if ($inlineFooter)
        <div class="report-layout-footer report-inline-footer">
            <table class="footer-inline-table"><tbody><tr>
                @if ($pageGeometry['footer']['numberFirst'])
                    <td class="footer-number-region" style="width: {{ 100 * $pageGeometry['footer']['numberWidth'] / ($pageGeometry['width'] - 80) }}%"><div style="height: {{ $pageGeometry['footer']['numberHeight'] }}pt"></div></td>
                    <td style="width: {{ 100 * $pageGeometry['footer']['gap'] / ($pageGeometry['width'] - 80) }}%"></td>
                @endif
                <td class="footer-content-region" style="width: {{ 100 * $pageGeometry['footer']['contentWidth'] / ($pageGeometry['width'] - 80) }}%">
                    @if ($footerText !== '')
                        <div style="{{ $footerStyleCss }}; white-space: pre-wrap; line-height: 1.4">{{ $footerText }}</div>
                    @endif
                </td>
                @if (! $pageGeometry['footer']['numberFirst'])
                    <td style="width: {{ 100 * $pageGeometry['footer']['gap'] / ($pageGeometry['width'] - 80) }}%"></td>
                    <td class="footer-number-region" style="width: {{ 100 * $pageGeometry['footer']['numberWidth'] / ($pageGeometry['width'] - 80) }}%"><div style="height: {{ $pageGeometry['footer']['numberHeight'] }}pt"></div></td>
                @endif
            </tr></tbody></table>
        </div>
    @elseif ($footerText !== '')
        <div class="report-layout-footer" style="{{ $footerStyleCss }}">{{ $footerText }}</div>
    @endif
</body>
</html>
