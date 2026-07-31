<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Sources;

/**
 * Immutable description of a single field exposed by a ReportSource.
 *
 * @property-read string $key
 * @property-read string $label
 * @property-read string $type
 * @property-read bool $sortable
 * @property-read bool $filterable
 * @property-read bool $aggregatable
 * @property-read bool $hidden
 */
final class ReportField
{
    /**
     * @param  string  $key  Dot-notation name e.g. "customer.name".
     * @param  string  $label
     * @param  string  $type  string|integer|decimal|boolean|date|datetime|json
     * @param  ?string  $selectExpr  Optional SQL select expression (defaults to key). Must be defined by source, never by user.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type = 'string',
        public readonly bool $sortable = true,
        public readonly bool $filterable = true,
        public readonly bool $aggregatable = false,
        public readonly bool $hidden = false,
        public readonly ?string $selectExpr = null,
        public readonly ?string $format = null,
        public readonly ?string $description = null,
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            key: $data['key'] ?? $data['field'] ?? throw new \InvalidArgumentException('ReportField requires a key.'),
            label: $data['label'] ?? ucfirst((string) ($data['key'] ?? '')),
            type: $data['type'] ?? 'string',
            sortable: $data['sortable'] ?? true,
            filterable: $data['filterable'] ?? true,
            aggregatable: $data['aggregatable'] ?? in_array($data['type'] ?? '', ['integer', 'decimal'], true),
            hidden: $data['hidden'] ?? false,
            selectExpr: $data['select_expr'] ?? $data['selectExpr'] ?? null,
            format: $data['format'] ?? null,
            description: $data['description'] ?? null,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'sortable' => $this->sortable,
            'filterable' => $this->filterable,
            'aggregatable' => $this->aggregatable,
            'hidden' => $this->hidden,
            'format' => $this->format,
            'description' => $this->description,
        ];
    }
}
