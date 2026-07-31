<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Http\Resources;

use ElgiborSolution\AdvancedReports\Models\ReportRun;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReportRun
 */
class ReportRunResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'uuid' => $this->uuid,
            'report_id' => $this->report_id,
            'status' => $this->status,
            'parameters' => $this->parameters,
            'row_count' => $this->row_count,
            'summary' => $this->summary,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),

            $this->mergeWhen($this->relationLoaded('report'), [
                'report' => new ReportResource($this->whenLoaded('report')),
            ]),
        ];
    }
}
