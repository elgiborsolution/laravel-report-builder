<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Contracts;

use ElgiborSolution\AdvancedReports\Sources\ReportField;
use ElgiborSolution\AdvancedReports\Sources\ReportParameter;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * A report source is the security boundary between end-user report
 * definitions and the underlying data. End-users only reference a source
 * by its key; they never pass raw SQL or table names.
 *
 * A source declares:
 *  - Its available fields (and their visibility/permissions)
 *  - Its parameters (validated at run-time)
 *  - A query builder closure that produces the base scoped query
 */
interface ReportSourceContract
{
    /**
     * Unique source key referenced by `definition.data_source`.
     */
    public function key(): string;

    /**
     * Human-readable label.
     */
    public function label(): string;

    /**
     * Description shown in designer/schema output.
     */
    public function description(): string;

    /**
     * The list of fields this source exposes.
     *
     * @return Collection<string, ReportField>
     */
    public function fields(): Collection;

    /**
     * Look up metadata for a declared source field.
     */
    public function field(string $key): ?ReportField;

    /**
     * The parameters this source accepts (used by the validator).
     *
     * @return Collection<string, ReportParameter>
     */
    public function parameters(): Collection;

    /**
     * Build the base query builder for this source. The engine applies
     * additional filters, groups, sorts, and aggregates on top.
     *
     * @param  array<string,mixed>  $parameters
     */
    public function query(array $parameters = []): Builder;
}
