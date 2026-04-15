<?php

declare(strict_types=1);

use CarmeloSantana\PHPLLMBenchy\Benchmark\BenchmarkRegistry;

it('defines the full v1 benchmark catalog with 50 capability points each', function (): void {
    $registry = new BenchmarkRegistry();
    $definitions = $registry->all();

    expect(array_keys($definitions))->toBe([
        'tool_use',
        'concurrent_tool_use',
        'memory_recall',
        'shell_execution',
        'php_script',
        'creative_story',
        'poem',
    ]);

    foreach ($definitions as $definition) {
        expect(array_sum($definition->capabilityWeights))->toBe(50);
        expect($definition->prompt)->not->toBe('');
        expect($definition->scenario)->not->toBe([]);
    }
});

it('returns a serializable catalog for the frontend', function (): void {
    $registry = new BenchmarkRegistry();
    $catalog = $registry->catalog();

    expect($catalog)->toHaveCount(7)
        ->and($catalog[0])->toHaveKeys(['id', 'name', 'description', 'prompt', 'capability_weights', 'scenario', 'tags']);
});