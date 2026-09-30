<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Tests\Fixtures;

use ElgiborSolution\AdvancedReports\Sources\ReportSource;
use Illuminate\Contracts\Database\Query\Builder;

class GroupingRowsReportSource extends ReportSource
{
    public function key(): string
    {
        return 'grouping_rows';
    }

    public function label(): string
    {
        return 'Grouping Rows';
    }

    public function description(): string
    {
        return 'Rows used to verify nested report grouping.';
    }

    protected function defineFields(): array
    {
        return [
            $this->field('category', 'Category', type: 'string'),
            $this->field('subcategory', 'Subcategory', type: 'string'),
            $this->field('quantity', 'Quantity', type: 'integer', aggregatable: true),
            $this->field('amount', 'Amount', type: 'decimal', aggregatable: true),
        ];
    }

    public function query(array $parameters = []): Builder
    {
        return \DB::table('grouping_rows');
    }
}

class PartialSelectionGroupingRowsReportSource extends GroupingRowsReportSource
{
    public function key(): string
    {
        return 'grouping_rows_partial';
    }

    public function query(array $parameters = []): Builder
    {
        return \DB::table('grouping_rows')->select('id');
    }
}
