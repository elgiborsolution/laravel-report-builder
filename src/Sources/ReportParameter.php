<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Sources;

/**
 * Immutable description of a parameter accepted by a ReportSource.
 *
 * @property-read string $name
 * @property-read string $type  string|integer|decimal|boolean|date|datetime|array
 * @property-read bool $required
 */
final class ReportParameter
{
    public function __construct(
        public readonly string $name,
        public readonly string $type = 'string',
        public readonly bool $required = false,
        public readonly mixed $default = null,
        public readonly ?array $allowedValues = null,
        public readonly ?string $description = null,
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? throw new \InvalidArgumentException('ReportParameter requires a name.'),
            type: $data['type'] ?? 'string',
            required: $data['required'] ?? false,
            default: $data['default'] ?? null,
            allowedValues: $data['allowed_values'] ?? $data['allowedValues'] ?? null,
            description: $data['description'] ?? null,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'required' => $this->required,
            'default' => $this->default,
            'allowed_values' => $this->allowedValues,
            'description' => $this->description,
        ];
    }
}
