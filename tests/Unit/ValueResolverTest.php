<?php

declare(strict_types=1);

use ElgiborSolution\AdvancedReports\Support\ValueResolver;
use ElgiborSolution\AdvancedReports\Tests\TestCase;

uses(TestCase::class);

it('resolves parameter tokens', function () {
    $r = new ValueResolver(['date_from' => '2026-01-01']);

    expect($r->resolve('{{date_from}}'))->toBe('2026-01-01');
});

it('resolves params.X and row.X dotted tokens', function () {
    $r = new ValueResolver(['x' => 'p'], ['y' => 'r']);

    expect($r->resolve('{{params.x}}'))->toBe('p')
        ->and($r->resolve('{{row.y}}'))->toBe('r');
});

it('embeds tokens inside surrounding text', function () {
    $r = new ValueResolver(['code' => 'X1']);

    expect($r->resolve('order-{{code}}'))->toBe('order-X1');
});

it('returns native values for full-token substitution', function () {
    $r = new ValueResolver(['id' => 42]);

    expect($r->resolve('{{id}}'))->toBe(42);
});

it('resolves nested row data via dot notation', function () {
    $r = new ValueResolver([], ['customer' => ['id' => 7]]);

    expect($r->resolve('{{row.customer.id}}'))->toBe(7);
});
