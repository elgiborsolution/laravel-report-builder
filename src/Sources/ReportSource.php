<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Sources;

use ElgiborSolution\AdvancedReports\Contracts\ReportSourceContract;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Base class developers extend to define a report source. Subclasses must
 * implement {@see query()} and typically declare {@see fields()} and
 * {@see parameters()} via the field()/param() helpers.
 *
 * Example:
 *
 *   class SalesOrderReportSource extends ReportSource {
 *       public function key(): string { return 'sales_orders'; }
 *       public function label(): string { return 'Sales Orders'; }
 *
 *       protected function defineFields(): array {
 *           return [
 *               $this->field('customer.name', 'Customer', type: 'string'),
 *               $this->field('total_amount', 'Amount', type: 'decimal', aggregatable: true),
 *           ];
 *       }
 *
 *       public function query(array $parameters = []): Builder {
 *           return SalesOrder::query()->with('customer');
 *       }
 *   }
 */
abstract class ReportSource implements ReportSourceContract
{
    /** @var Collection<string, ReportField>|null */
    protected ?Collection $fieldsCache = null;

    /** @var Collection<string, ReportParameter>|null */
    protected ?Collection $parametersCache = null;

    public function description(): string
    {
        return '';
    }

    /**
     * Override to declare fields. Each entry must be a ReportField or array.
     *
     * @return array<int, ReportField|array<string,mixed>>
     */
    protected function defineFields(): array
    {
        return [];
    }

    /**
     * Override to declare parameters.
     *
     * @return array<int, ReportParameter|array<string,mixed>>
     */
    protected function defineParameters(): array
    {
        return [];
    }

    public function fields(): Collection
    {
        if ($this->fieldsCache instanceof Collection) {
            return $this->fieldsCache;
        }

        $fields = [];
        foreach ($this->defineFields() as $f) {
            $field = $f instanceof ReportField ? $f : ReportField::fromArray($f);
            $fields[$field->key] = $field;
        }

        return $this->fieldsCache = collect($fields);
    }

    public function parameters(): Collection
    {
        if ($this->parametersCache instanceof Collection) {
            return $this->parametersCache;
        }

        $params = [];
        foreach ($this->defineParameters() as $p) {
            $param = $p instanceof ReportParameter ? $p : ReportParameter::fromArray($p);
            $params[$param->name] = $param;
        }

        return $this->parametersCache = collect($params);
    }

    /**
     * Look up a field by key. Hidden fields are returned here so internal
     * resolvers (filters/formulas) can use them, but renderers MUST skip them
     * unless explicitly required by an aggregate/internal reference.
     */
    public function field(string $key): ?ReportField
    {
        return $this->fields()->get($key);
    }

    /**
     * The base table/alias for SELECT qualification. Override if your query
     * uses joins/aliases. Default: the underlying Eloquent model table.
     */
    public function baseTable(): ?string
    {
        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Field/param helper factories
    |--------------------------------------------------------------------------
    */

    /** @param array<string,mixed> ...$with */
    protected function field(string $key, string $label, string $type = 'string', ...$with): ReportField
    {
        return new ReportField(key: $key, label: $label, type: $type, ...$with);
    }

    protected function param(string $name, string $type = 'string', bool $required = false, ...$with): ReportParameter
    {
        return new ReportParameter(name: $name, type: $type, required: $required, ...$with);
    }

    /**
     * Convenience: detect and return the Eloquent model of a query.
     *
     * @return Model|null
     */
    protected function modelFor(Builder $query): ?Model
    {
        return $query->getModel() ?? null;
    }
}
