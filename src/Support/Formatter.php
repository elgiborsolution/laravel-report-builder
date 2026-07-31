<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Support;

use Carbon\Carbon;
use Illuminate\Support\Number;

/**
 * Centralized value formatting for all renderers. Each format maps to a
 * callback that takes the raw value + optional options. Custom callbacks
 * can be registered via config('advanced-reports.formats').
 */
final class Formatter
{
    /** @var array<string,callable> */
    protected array $custom = [];

    /** @param array<string,callable> $custom */
    public function __construct(array $custom = [])
    {
        $this->custom = $custom;
    }

    public function format(mixed $value, ?string $format, array $options = []): mixed
    {
        if ($format === null || $format === '') {
            return $value;
        }

        if (isset($this->custom[$format])) {
            return ($this->custom[$format])($value, $options);
        }

        return match ($format) {
            'string' => $value === null ? '' : (string) $value,
            'integer' => $value === null ? null : (int) $value,
            'decimal' => $value === null ? null : (float) $value,
            'currency' => $this->currency($value, $options),
            'percentage' => $this->percentage($value, $options),
            'date' => $this->date($value, $options),
            'datetime' => $this->datetime($value, $options),
            'boolean' => $this->boolean($value),
            default => $value,
        };
    }

    protected function currency(mixed $value, array $options): ?string
    {
        if ($value === null) {
            return null;
        }
        $currency = $options['currency'] ?? config('advanced-reports.formats.currency.code', 'USD');

        try {
            return Number::currency((float) $value, $currency);
        } catch (\Throwable) {
            return $currency.' '.number_format((float) $value, 2);
        }
    }

    protected function percentage(mixed $value, array $options): ?string
    {
        if ($value === null) {
            return null;
        }
        $decimals = $options['decimals'] ?? 2;

        try {
            return Number::percentage((float) $value, maxPrecision: $decimals);
        } catch (\Throwable) {
            return number_format((float) $value, $decimals).'%';
        }
    }

    protected function date(mixed $value, array $options): ?string
    {
        if ($value === null) {
            return null;
        }
        $format = $options['date_format']
            ?? config('advanced-reports.formats.date', 'Y-m-d');

        return $this->asCarbon($value)->format($format);
    }

    protected function datetime(mixed $value, array $options): ?string
    {
        if ($value === null) {
            return null;
        }
        $format = $options['datetime_format']
            ?? config('advanced-reports.formats.datetime', 'Y-m-d H:i:s');

        return $this->asCarbon($value)->format($format);
    }

    protected function boolean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value ? 'Yes' : 'No';
    }

    protected function asCarbon(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        return Carbon::parse($value);
    }
}
