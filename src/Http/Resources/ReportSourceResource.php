<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Represents a registered source's schema for the designer UI.
 */
class ReportSourceResource extends JsonResource
{
    public function toArray($request): array
    {
        $source = $this->resource;

        return [
            'key' => $source->key(),
            'label' => $source->label(),
            'description' => $source->description(),
            'fields' => $source->fields()
                ->reject(fn ($f) => $f->hidden)
                ->map(fn ($f) => $f->toArray())
                ->values()
                ->all(),
            'parameters' => $source->parameters()
                ->map(fn ($p) => $p->toArray())
                ->values()
                ->all(),
        ];
    }
}
