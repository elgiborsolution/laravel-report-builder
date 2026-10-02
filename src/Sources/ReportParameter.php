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
        public readonly ?string $label = null,
        public readonly ?string $format = null,
        public readonly ?string $operator = null,
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
            label: $data['label'] ?? null,
            format: $data['format'] ?? null,
            operator: $data['operator'] ?? null,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'type' => $this->type,
            'required' => $this->required,
            'default' => $this->default,
            'allowed_values' => $this->allowedValues,
            'description' => $this->description,
        ];

        if ($this->label !== null && $this->label !== '') {
            $data['label'] = $this->label;
        }
        if ($this->format !== null && $this->format !== '') {
            $data['format'] = $this->format;
        }
        if ($this->operator !== null && $this->operator !== '') {
            $data['operator'] = $this->operator;
        }

        return $data;
    }
}
