<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPLLMBenchy\Config;

final readonly class SessionSeedConfigurationValidator
{
    public function __construct(
        private AppConfig $config,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function resolve(array $payload, int $modelCount, int $benchmarkCount, int $runsPerBenchmark): SessionSeedConfiguration
    {
        $type = $this->resolveType($payload['seed_type'] ?? null);
        $frequency = $this->resolveFrequency($payload['seed_frequency'] ?? null);

        if ($type === SeedType::Fixed) {
            $frequency = SeedFrequency::PerSession;
        }

        if ($type === SeedType::Random) {
            return new SessionSeedConfiguration(
                seed: random_int(0, PHP_INT_MAX),
                type: $type,
                frequency: $frequency,
            );
        }

        $rawSeed = array_key_exists('seed', $payload) ? $payload['seed'] : $this->config->defaultSeed();
        $seed = $this->parseSeed($rawSeed);

        if ($seed === null) {
            throw new \InvalidArgumentException('Seed is required for fixed and iterative modes.');
        }

        if ($type === SeedType::Iterative) {
            $maxOffset = match ($frequency) {
                SeedFrequency::PerSession => 0,
                SeedFrequency::PerTest => max(0, ($modelCount * $benchmarkCount) - 1),
                SeedFrequency::PerRun => max(0, ($modelCount * $benchmarkCount * $runsPerBenchmark) - 1),
            };

            if ($seed > PHP_INT_MAX - $maxOffset) {
                throw new \InvalidArgumentException('Seed is too large for the selected iterative frequency.');
            }
        }

        return new SessionSeedConfiguration(
            seed: $seed,
            type: $type,
            frequency: $frequency,
        );
    }

    private function resolveType(mixed $value): SeedType
    {
        if ($value === null || $value === '') {
            return $this->config->defaultSeedType();
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException('Seed type must be a string.');
        }

        $type = SeedType::tryFrom(trim($value));
        if ($type instanceof SeedType) {
            return $type;
        }

        throw new \InvalidArgumentException('Seed type must be one of: ' . implode(', ', SeedType::values()) . '.');
    }

    private function resolveFrequency(mixed $value): SeedFrequency
    {
        if ($value === null || $value === '') {
            return $this->config->defaultSeedFrequency();
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException('Seed change frequency must be a string.');
        }

        $frequency = SeedFrequency::tryFrom(trim($value));
        if ($frequency instanceof SeedFrequency) {
            return $frequency;
        }

        throw new \InvalidArgumentException('Seed change frequency must be one of: ' . implode(', ', SeedFrequency::values()) . '.');
    }

    private function parseSeed(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            if ($value < 0) {
                throw new \InvalidArgumentException('Seed must be a non-negative integer.');
            }

            return $value;
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException('Seed must be a non-negative integer.');
        }

        $normalized = trim($value);
        if ($normalized === '' || preg_match('/^\d+$/', $normalized) !== 1) {
            throw new \InvalidArgumentException('Seed must be a non-negative integer.');
        }

        $max = (string) PHP_INT_MAX;
        $digits = ltrim($normalized, '0');
        $digits = $digits === '' ? '0' : $digits;

        if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            throw new \InvalidArgumentException('Seed must be less than or equal to ' . $max . '.');
        }

        return (int) $digits;
    }
}