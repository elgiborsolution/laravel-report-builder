<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Sources;

use ElgiborSolution\AdvancedReports\Contracts\ReportSourceContract;
use ElgiborSolution\AdvancedReports\Exceptions\SourceNotRegisteredException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;

/**
 * Registry of available report sources. The Manager and Engine resolve
 * sources through this registry; it is the single authority for "which
 * sources exist" and enforces the security boundary (only registered
 * sources may be referenced by a definition).
 */
class SourceRegistry
{
    /** @var array<string, class-string<ReportSourceContract>|ReportSourceContract> */
    protected array $sources = [];

    public function __construct(protected Container $container) {}

    /**
     * Register a source. May pass a class-string (resolved lazily) or instance.
     *
     * @param  class-string<ReportSourceContract>|ReportSourceContract  $source
     * @param  string|null  $key  Override the source's own key.
     */
    public function register(string|ReportSourceContract $source, ?string $key = null): static
    {
        if ($source instanceof ReportSourceContract) {
            $key ??= $source->key();
            $this->sources[$key] = $source;

            return $this;
        }

        // Class-string: do not resolve yet (deferred to keep registration cheap).
        $resolved = $this->container->make($source);
        $key ??= $resolved->key();

        $this->sources[$key] = $resolved;

        return $this;
    }

    public function has(string $key): bool
    {
        return isset($this->sources[$key]);
    }

    /**
     * @throws SourceNotRegisteredException
     */
    public function get(string $key): ReportSourceContract
    {
        if (! isset($this->sources[$key])) {
            throw SourceNotRegisteredException::forKey($key);
        }

        return $this->sources[$key];
    }

    /**
     * @return Collection<string, ReportSourceContract>
     */
    public function all(): Collection
    {
        return collect($this->sources);
    }

    /**
     * @return Collection<int, string>
     */
    public function keys(): Collection
    {
        return collect(array_keys($this->sources));
    }

    public function flush(): static
    {
        $this->sources = [];

        return $this;
    }
}
