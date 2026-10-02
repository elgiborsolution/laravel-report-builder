<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Engine;

/**
 * Resolves parameter values:
 *  - merges definition defaults with provided runtime values
 *  - substitutes {{token}} references using ValueResolver
 *  - applies type coercion (date, int, etc.)
 */
final class ParameterResolver
{
    public function __construct(protected \ElgiborSolution\AdvancedReports\Support\ValueResolver $resolver) {}

    /**
     * @param  array<int,array>  $declared
     * @param  array<string,mixed>  $provided
     * @return array<string,mixed>
     */
    public function resolve(array $declared, array $provided): array
    {
        $resolved = [];

        foreach ($declared as $param) {
            $name = $param['name'];
            $value = array_key_exists($name, $provided)
                ? $provided[$name]
                : ($param['default'] ?? null);

            $value = $this->resolver->setParams($provided)->resolve($value);
            $value = $this->coerce($value, $param['type'] ?? 'string');

            $resolved[$name] = $value;
        }

        return $resolved;
    }

    protected function coerce(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'integer' => (int) $value,
            'decimal' => (float) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value,
            'date', 'datetime' => $value instanceof \DateTimeInterface ? $value : \Carbon\Carbon::parse($value),
            'array' => is_array($value) ? $value : [$value],
            default => $value,
        };
    }
}
