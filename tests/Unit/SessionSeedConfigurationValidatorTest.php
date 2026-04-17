<?php

declare(strict_types=1);

use CarmeloSantana\PHPLLMBenchy\Config\AppConfig;
use CarmeloSantana\PHPLLMBenchy\Config\SessionSeedConfigurationValidator;
use CarmeloSantana\PHPLLMBenchy\Storage\Database;

it('requires a seed for fixed mode', function (): void {
    $validator = validator();

    expect(fn() => $validator->resolve([
        'seed_type' => 'fixed',
        'seed_frequency' => 'per_run',
        'seed' => '',
    ], 1, 1, 1))->toThrow(InvalidArgumentException::class, 'Seed is required for fixed and iterative modes.');
});

it('normalizes fixed mode to per-session frequency', function (): void {
    $validator = validator();
    $configuration = $validator->resolve([
        'seed_type' => 'fixed',
        'seed_frequency' => 'per_run',
        'seed' => '12',
    ], 1, 1, 3);

    expect($configuration->seed)->toBe(12)
        ->and($configuration->type->value)->toBe('fixed')
        ->and($configuration->frequency->value)->toBe('per_session');
});

it('rejects malformed and negative seeds', function (mixed $seed): void {
    $validator = validator();

    expect(fn() => $validator->resolve([
        'seed_type' => 'iterative',
        'seed_frequency' => 'per_run',
        'seed' => $seed,
    ], 1, 1, 1))->toThrow(InvalidArgumentException::class, 'Seed must be a non-negative integer.');
})->with([['abc'], ['-1'], [3.14], [true]]);

it('rejects unknown seed enums', function (): void {
    $validator = validator();

    expect(fn() => $validator->resolve([
        'seed_type' => 'chaos',
        'seed_frequency' => 'per_run',
        'seed' => '1',
    ], 1, 1, 1))->toThrow(InvalidArgumentException::class, 'Seed type must be one of: random, fixed, iterative.');

    expect(fn() => $validator->resolve([
        'seed_type' => 'iterative',
        'seed_frequency' => 'per_everything',
        'seed' => '1',
    ], 1, 1, 1))->toThrow(InvalidArgumentException::class, 'Seed change frequency must be one of: per_session, per_test, per_run.');
});

it('rejects iterative seeds that would overflow', function (): void {
    $validator = validator();

    expect(fn() => $validator->resolve([
        'seed_type' => 'iterative',
        'seed_frequency' => 'per_run',
        'seed' => (string) PHP_INT_MAX,
    ], 1, 2, 2))->toThrow(InvalidArgumentException::class, 'Seed is too large for the selected iterative frequency.');
});

it('generates a random base seed when random mode is selected', function (): void {
    $validator = validator();
    $configuration = $validator->resolve([
        'seed_type' => 'random',
        'seed_frequency' => 'per_test',
        'seed' => '999',
    ], 2, 2, 2);

    expect($configuration->type->value)->toBe('random')
        ->and($configuration->frequency->value)->toBe('per_test')
        ->and($configuration->seed)->toBeInt()
        ->and($configuration->seed)->toBeGreaterThanOrEqual(0);
});

function validator(): SessionSeedConfigurationValidator
{
    $databasePath = sys_get_temp_dir() . '/php-llm-benchy-tests/db-' . uniqid('', true) . '.sqlite';
    putenv('DATABASE_PATH=' . $databasePath);
    $_ENV['DATABASE_PATH'] = $databasePath;

    $config = new AppConfig(dirname(__DIR__, 2));
    $database = new Database($config);
    $database->migrate();

    return new SessionSeedConfigurationValidator($config);
}