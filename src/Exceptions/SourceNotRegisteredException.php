<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Exceptions;

class SourceNotRegisteredException extends AdvancedReportException
{
    public static function forKey(string $key): self
    {
        return new self("Report source [{$key}] is not registered. Register it via AdvancedReports::registerSource().");
    }
}
