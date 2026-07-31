<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Export\DTO;

/**
 * Value object for export options passed to ExportManager.
 */
final class ExportOptions
{
    public function __construct(
        public readonly string $format,
        public readonly ?string $filename = null,
        public readonly ?string $disk = null,
        public readonly ?string $path = null,
        public readonly bool $queued = false,
        public readonly ?string $queueConnection = null,
        public readonly ?string $queueName = null,
        public readonly ?string $paper = null,
        public readonly ?string $orientation = null,
        public readonly bool $includeAggregates = true,
        public readonly bool $bom = true,
        public readonly ?string $delimiter = null,
        public readonly array $extra = [],
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            format: $data['format'] ?? config('advanced-reports.export.default_format', 'pdf'),
            filename: $data['filename'] ?? null,
            disk: $data['disk'] ?? config('advanced-reports.export.disk'),
            path: $data['path'] ?? config('advanced-reports.export.path'),
            queued: (bool) ($data['queued'] ?? config('advanced-reports.export.queue.enabled', false)),
            queueConnection: $data['queue_connection'] ?? config('advanced-reports.export.queue.connection'),
            queueName: $data['queue'] ?? config('advanced-reports.export.queue.queue'),
            paper: $data['paper'] ?? config('advanced-reports.pdf.options.paper'),
            orientation: $data['orientation'] ?? config('advanced-reports.pdf.options.orientation'),
            includeAggregates: (bool) ($data['include_aggregates'] ?? true),
            bom: (bool) ($data['bom'] ?? true),
            delimiter: $data['delimiter'] ?? ',',
            extra: $data['extra'] ?? [],
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'format' => $this->format,
            'filename' => $this->filename,
            'disk' => $this->disk,
            'path' => $this->path,
            'queued' => $this->queued,
            'queue_connection' => $this->queueConnection,
            'queue' => $this->queueName,
            'paper' => $this->paper,
            'orientation' => $this->orientation,
            'include_aggregates' => $this->includeAggregates,
            'bom' => $this->bom,
            'delimiter' => $this->delimiter,
            'extra' => $this->extra,
        ];
    }
}
