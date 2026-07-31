<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Exceptions;

class ReportNotFoundException extends AdvancedReportException
{
    public static function forCode(string $code): self
    {
        return new self("Report with code [{$code}] was not found.");
    }

    public static function forId(int|string $id): self
    {
        return new self("Report with id [{$id}] was not found.");
    }
}
