<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Models;

use Illuminate\Database\Eloquent\Model;

abstract class BaseModel extends Model
{
    public function getConnectionName(): ?string
    {
        return config('advanced-reports.database.connection');
    }

    /** @param array<string,mixed> $attributes */
    public function setRawAttributes(array $attributes, $sync = false): static
    {
        // Normalize JSON attributes (some drivers return strings).
        foreach ($this->getJsonCastAttributes() as $attr) {
            if (isset($attributes[$attr]) && is_string($attributes[$attr])) {
                $decoded = json_decode($attributes[$attr], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $attributes[$attr] = $decoded;
                }
            }
        }

        return parent::setRawAttributes($attributes, $sync);
    }

    /** @return array<int,string> */
    protected function getJsonCastAttributes(): array
    {
        return array_keys(array_filter($this->casts, fn ($cast) => in_array($cast, ['array', 'json', 'collection'], true)));
    }
}
