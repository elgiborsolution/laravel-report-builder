<?php

declare(strict_types=1);

use ElgiborSolution\AdvancedReports\Support\SafeExpressionEvaluator;
use ElgiborSolution\AdvancedReports\Tests\TestCase;

uses(TestCase::class);

it('evaluates arithmetic over fields', function () {
    $eval = new SafeExpressionEvaluator();

    expect($eval->evaluate('total_amount * 0.11', ['total_amount' => 1000]))
        ->toBe(110.0);
});

it('flattens dotted field names for evaluation', function () {
    $eval = new SafeExpressionEvaluator();

    expect($eval->evaluate('customer_name * 2', ['customer.name' => 'x']))
        ->not->toThrow(\Throwable::class);

    expect($eval->evaluate('total_amount + internal_cost', ['total_amount' => 100, 'internal_cost' => 25]))
        ->toBe(125.0);
});

it('validates known variables without throwing', function () {
    $eval = new SafeExpressionEvaluator();

    $errors = $eval->validate('total_amount * 0.11', ['total_amount', 'internal_cost']);

    expect($errors)->toBeEmpty();
});

it('accepts flattened references for dotted source-field keys', function () {
    $eval = new SafeExpressionEvaluator();

    expect($eval->validate('customer_name == "Acme"', ['customer.name']))
        ->toBeEmpty();
});

it('rejects unknown identifiers as syntax errors', function () {
    $eval = new SafeExpressionEvaluator();

    $errors = $eval->validate('secret_field * 2', ['total_amount']);

    expect($errors)->not->toBeEmpty();
});

it('supports registered helper functions like round and abs', function () {
    $eval = new SafeExpressionEvaluator();

    expect($eval->evaluate('round(total_amount * 0.11, 2)', ['total_amount' => 1000]))
        ->toBe(110.0)
        ->and($eval->evaluate('abs(internal_cost - total_amount)', ['total_amount' => 100, 'internal_cost' => 130]))
        ->toBe(30.0);
});
