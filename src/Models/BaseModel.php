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
        // Eloquent JSON casts expect raw JSON strings. Some drivers may return
        // decoded arrays, so encode those before handing attributes to Eloquent.
        foreach ($this->getJsonCastAttributes() as $attr) {
            if (isset($attributes[$attr]) && is_array($attributes[$attr])) {
                $attributes[$attr] = json_encode($attributes[$attr], JSON_THROW_ON_ERROR);
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
