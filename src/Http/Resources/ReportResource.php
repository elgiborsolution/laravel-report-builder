<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Http\Resources;

use ElgiborSolution\AdvancedReports\Models\Report;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Report
 */
class ReportResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'data_source' => $this->data_source,
            'definition' => $this->definition,
            'is_public' => $this->is_public,
            'is_active' => $this->is_active,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Count metadata (avoids N+1 when loaded).
            $this->mergeWhen($this->relationLoaded('runs'), [
                'runs_count' => $this->runs_count ?? $this->runs->count(),
            ]),
            $this->mergeWhen($this->relationLoaded('exports'), [
                'exports_count' => $this->exports_count ?? $this->exports->count(),
            ]),
        ];
    }
}
