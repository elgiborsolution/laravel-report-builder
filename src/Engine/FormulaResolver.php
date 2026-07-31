<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Engine;

use ElgiborSolution\AdvancedReports\Contracts\ExpressionEvaluator;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * Evaluates formula fields row-by-row using {@see ExpressionEvaluator}.
 * Formula results are injected into each row under their declared name,
 * making them usable by columns, aggregates, sorts, and drilldowns.
 */
final class FormulaResolver
{
    public function __construct(protected ExpressionEvaluator $evaluator) {}

    /**
     * @param  Collection<int,array>|LazyCollection  $rows
     * @param  array<int,array>  $formulas  Each: [{name, expression, type, format?}]
     * @return Collection<int,array>|LazyCollection
     */
    public function apply(Collection|LazyCollection $rows, array $formulas): Collection|LazyCollection
    {
        if (empty($formulas)) {
            return $rows;
        }

        $map = function (array $row) use ($formulas) {
            foreach ($formulas as $f) {
                $name = $f['name'] ?? null;
                $expression = $f['expression'] ?? null;
                if (! $name || ! $expression) {
                    continue;
                }

                try {
                    $row[$name] = $this->evaluator->evaluate((string) $expression, $row);
                } catch (\Throwable) {
                    $row[$name] = null;
                }
            }

            return $row;
        };

        return $rows instanceof LazyCollection
            ? LazyCollection::make(fn () => yield from $rows->map($map))
            : $rows->map($map);
    }
}
