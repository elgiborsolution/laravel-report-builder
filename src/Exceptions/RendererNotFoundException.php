<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Exceptions;

class RendererNotFoundException extends AdvancedReportException
{
    public static function forFormat(string $format): self
    {
        return new self("No renderer registered for format [{$format}]. Register one in config('advanced-reports.renderers').");
    }
}
