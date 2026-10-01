@php
/**
 * One subtotal/grand-total row resolved by SummaryRowLayout. Segments cover
 * every column exactly once; a label segment may span several columns. Each
 * cell is styled by the aggregate whose value or label it shows.
 *
 * @var array $summaryRow  Presentation row with a resolved 'summary' entry.
 * @var string $rowClass
 * @var int $columnCount
 */
$summary = $summaryRow['summary'];
$level = $summaryRow['level'] ?? 0;
@endphp
@if ($summary['label_row'])
    <tr class="{{ $rowClass }}-label" data-group-level="{{ $level }}">
        <td colspan="{{ max(1, $columnCount) }}" style="{{ $summary['label_row']['css'] }}">{{ $summary['label_row']['text'] }}</td>
    </tr>
@endif
<tr class="{{ $rowClass }}" data-group-level="{{ $level }}">
    @foreach ($summary['segments'] as $segment)
        <td colspan="{{ $segment['span'] }}" data-summary-kind="{{ $segment['kind'] }}" style="{{ $segment['css'] }}">@if ($segment['kind'] === 'value' && count($segment['values']) > 1)@foreach ($segment['values'] as $value)<span class="aggregate-value">{{ $value['label'] }}: {{ is_scalar($value['value'] ?? null) ? $value['value'] : '' }}</span>@endforeach @else{{ is_scalar($segment['text']) ? $segment['text'] : '' }}@endif</td>
    @endforeach
</tr>
