<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Support;

/**
 * Resolves template tokens like {{param.x}} and {{row.x}} in string values
 * against a given context (parameters + row data). Used by drilldowns,
 * filters, and parameter defaults.
 */
final class ValueResolver
{
    /** @var array<string,mixed> */
    protected array $params = [];

    /** @var array<string,mixed> */
    protected array $row = [];

    /**
     * @param  array<string,mixed>  $params
     * @param  array<string,mixed>  $row
     */
    public function __construct(array $params = [], array $row = [])
    {
        $this->params = $params;
        $this->row = $row;
    }

    public function setParams(array $params): self
    {
        $this->params = $params;

        return $this;
    }

    public function setRow(array $row): self
    {
        $this->row = $row;

        return $this;
    }

    /**
     * Resolve {{token}} references inside the given value. Pass-through scalars.
     */
    public function resolve(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($v) => $this->resolve($v), $value);
        }

        if (! is_string($value)) {
            return $value;
        }

        // Full-token: "{{x}}" -> resolve directly to native value.
        if (preg_match('/^\s*\{\{(.+)\}\}\s*$/', $value, $m)) {
            return $this->lookup(trim($m[1]));
        }

        // Embedded tokens: "around {{x}} text" -> string interpolation.
        return preg_replace_callback('/\{\{(.+?)\}\}/', function ($m) {
            $resolved = $this->lookup(trim($m[1]));

            return is_scalar($resolved) || $resolved === null ? (string) $resolved : '';
        }, $value) ?? $value;
    }

    protected function lookup(string $key): mixed
    {
        // param.X / params.X / row.X
        if (preg_match('/^(?:param|params)\.(.+)$/', $key, $m)) {
            return data_get($this->params, $m[1]);
        }
        if (preg_match('/^row\.(.+)$/', $key, $m)) {
            return data_get($this->row, $m[1]);
        }

        // Bare identifier: prefer row then params.
        return data_get($this->row, $key) ?? data_get($this->params, $key);
    }
}
