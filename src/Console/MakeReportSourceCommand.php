<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Console;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Input\InputOption;

class MakeReportSourceCommand extends GeneratorCommand
{
    protected $name = 'make:report-source';

    protected $description = 'Create a new report source class.';

    protected $type = 'Report Source';

    protected function getStub(): string
    {
        return $this->resolveStubPath('/stubs/report-source.stub');
    }

    /**
     * Replace additional stub placeholders beyond what GeneratorCommand does.
     */
    protected function replaceClass(string $stub, string $name): string
    {
        $class = str_replace($this->getNamespace($name).'\\', '', $name);

        $key = strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $class));
        $key = preg_replace('/ReportSource$/i', '', $key) ?: $key;
        $label = ucwords(str_replace('_', ' ', $key));
        $table = str_replace(' ', '_', strtolower($label)).'s';

        return str_replace(
            ['{{ class }}', '{{ namespace }}', '{{ key }}', '{{ label }}', '{{ table }}'],
            [$class, $this->getNamespace($name), $key, $label, $table],
            $stub
        );
    }

    protected function resolveStubPath(string $stub): string
    {
        return file_exists($customPath = $this->laravel->basePath(trim($stub, '/')))
            ? $customPath
            : __DIR__.$stub;
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Reports\Sources';
    }

    /** @return array<int,InputOption> */
    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if it already exists'],
        ];
    }
}
