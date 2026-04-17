<?php

declare(strict_types=1);

use CarmeloSantana\PHPLLMBenchy\Config\SeedFrequency;
use CarmeloSantana\PHPLLMBenchy\Config\SeedType;
use CarmeloSantana\PHPLLMBenchy\Config\SessionSeedConfiguration;
use CarmeloSantana\PHPLLMBenchy\Runner\AttemptSeedResolver;

it('keeps fixed seeds constant', function (): void {
    $resolver = new AttemptSeedResolver();
    $configuration = new SessionSeedConfiguration(42, SeedType::Fixed, SeedFrequency::PerSession);

    expect($resolver->resolve($configuration, 0, 0))->toBe(42)
        ->and($resolver->resolve($configuration, 4, 8))->toBe(42);
});

it('increments iterative seeds per test boundary', function (): void {
    $resolver = new AttemptSeedResolver();
    $configuration = new SessionSeedConfiguration(10, SeedType::Iterative, SeedFrequency::PerTest);

    expect($resolver->resolve($configuration, 0, 0))->toBe(10)
        ->and($resolver->resolve($configuration, 0, 1))->toBe(10)
        ->and($resolver->resolve($configuration, 1, 2))->toBe(11)
        ->and($resolver->resolve($configuration, 2, 5))->toBe(12);
});

it('increments iterative seeds per run boundary', function (): void {
    $resolver = new AttemptSeedResolver();
    $configuration = new SessionSeedConfiguration(10, SeedType::Iterative, SeedFrequency::PerRun);

    expect($resolver->resolve($configuration, 0, 0))->toBe(10)
        ->and($resolver->resolve($configuration, 0, 1))->toBe(11)
        ->and($resolver->resolve($configuration, 1, 2))->toBe(12);
});

it('derives deterministic random-like seeds from the session base seed', function (): void {
    $resolver = new AttemptSeedResolver();
    $configuration = new SessionSeedConfiguration(123, SeedType::Random, SeedFrequency::PerRun);

    $first = $resolver->resolve($configuration, 0, 1);
    $second = $resolver->resolve($configuration, 0, 2);

    expect($resolver->resolve($configuration, 0, 0))->toBe(123)
        ->and($first)->not->toBe(123)
        ->and($second)->not->toBe($first)
        ->and($resolver->resolve($configuration, 0, 1))->toBe($first);
});