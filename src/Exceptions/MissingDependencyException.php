<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Exceptions;

class MissingDependencyException extends AdvancedReportException
{
    public static function forPackage(string $package, string $feature): self
    {
        return new self(
            "Package [{$package}] is required to use [{$feature}]. ".
            "Install it with: composer require {$package}"
        );
    }
}
