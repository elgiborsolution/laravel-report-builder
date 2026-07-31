@php
/**
 * PDF view. Wraps the HTML view with print-friendly tweaks. The PdfRenderer
 * renders html.blade.php first, but this view is published separately so
 * users can customize the PDF-only layout (page breaks, headers/footers).
 *
 * @var \ElgiborSolution\AdvancedReports\Models\Report $report
 * @var array $columns
 * @var array $rows
 * @var array $aggregates
 */
@endphp
@extends('advanced-reports::reports.html')

@section('styles')
    @parent
    <style>
        @page { margin: 1.4cm; }
        table { font-size: 8pt; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        h1 { font-size: 14pt; }
        body { margin: 0; }
    </style>
@endsection
