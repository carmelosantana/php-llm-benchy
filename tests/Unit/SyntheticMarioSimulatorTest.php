<?php

declare(strict_types=1);

use CarmeloSantana\PHPLLMBenchy\Benchmark\SyntheticMarioBenchmarkFixture;
use CarmeloSantana\PHPLLMBenchy\Benchmark\SyntheticMarioSimulator;

it('completes the synthetic mario course with a fast deterministic sequence', function (): void {
    $simulator = new SyntheticMarioSimulator(SyntheticMarioBenchmarkFixture::scenario());

    $simulator->pressButtons([
        ['button' => 'RIGHT', 'frames' => 10],
        ['button' => 'B', 'frames' => 18],
        ['button' => 'RIGHT', 'frames' => 12],
        ['button' => 'B', 'frames' => 18],
        ['button' => 'RIGHT', 'frames' => 12],
        ['button' => 'B', 'frames' => 18],
        ['button' => 'RIGHT', 'frames' => 20],
    ]);

    $summary = $simulator->summary();

    expect($summary['completed'])->toBeTrue()
        ->and($summary['failed'])->toBeFalse()
        ->and($summary['checkpoints_cleared'])->toBe($summary['checkpoint_count'])
        ->and($summary['frames_used'])->toBeLessThan(260);
});

it('fails the synthetic mario course when it runs straight into the first hazard', function (): void {
    $simulator = new SyntheticMarioSimulator(SyntheticMarioBenchmarkFixture::scenario());

    $simulator->pressButtons([
        ['button' => 'RIGHT', 'frames' => 16],
    ]);

    $summary = $simulator->summary();

    expect($summary['completed'])->toBeFalse()
        ->and($summary['failed'])->toBeTrue()
        ->and($summary['failure_reason'])->toBe('collision_with_goomba')
        ->and($summary['deaths'])->toBe(1);
});