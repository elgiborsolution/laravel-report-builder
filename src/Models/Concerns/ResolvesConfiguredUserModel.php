<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Models\Concerns;

use LogicException;

trait ResolvesConfiguredUserModel
{
    /**
     * Resolve the host application's user model without assuming its table,
     * primary-key type, or auth provider name.
     */
    protected function configuredUserModel(): string
    {
        $model = config('advanced-reports.user_model');

        if (! is_string($model) || $model === '') {
            $guard = config('auth.defaults.guard');
            $provider = is_string($guard) ? config("auth.guards.{$guard}.provider") : null;
            $model = is_string($provider) ? config("auth.providers.{$provider}.model") : null;
        }

        if (! is_string($model) || $model === '') {
            throw new LogicException(
                'Configure advanced-reports.user_model to use the package user relationships.'
            );
        }

        return $model;
    }
}
