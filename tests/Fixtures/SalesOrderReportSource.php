<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Tests\Fixtures;

use ElgiborSolution\AdvancedReports\Sources\ReportSource;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Sample source used by the test suite. Backed by the in-memory
 * sales_orders table created in TestCase::setUp().
 */
class SalesOrderReportSource extends ReportSource
{
    public function key(): string
    {
        return 'sales_orders';
    }

    public function label(): string
    {
        return 'Sales Orders';
    }

    public function description(): string
    {
        return 'All sales orders with customer and amount.';
    }

    protected function defineFields(): array
    {
        return [
            $this->field('order_number', 'Order No', type: 'string'),
            $this->field('order_date', 'Order Date', type: 'date'),
            $this->field('customer_name', 'Customer', type: 'string'),
            $this->field('total_amount', 'Amount', type: 'decimal', aggregatable: true),
            // hidden field: should not appear in output but available to filters/formulas
            $this->field('internal_cost', 'Cost', type: 'decimal', hidden: true),
        ];
    }

    protected function defineParameters(): array
    {
        return [
            $this->param('date_from', 'date', required: true),
            $this->param('date_to', 'date', required: true),
        ];
    }

    public function query(array $parameters = []): Builder
    {
        return \DB::table('sales_orders');
    }
}
