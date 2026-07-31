<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Adds UUID boot logic to a model.
 */
trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::creating(function (self $model) {
            if (empty($model->getAttribute('uuid'))) {
                $model->setAttribute('uuid', (string) Str::orderedUuid());
            }
        });
    }

    public function getRouteKey(): string
    {
        return $this->getAttribute('uuid');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where('uuid', $value)->firstOrFail();
    }
}
