<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPLLMBenchy\Runner;

use CarmeloSantana\PHPLLMBenchy\Config\SeedFrequency;
use CarmeloSantana\PHPLLMBenchy\Config\SeedType;
use CarmeloSantana\PHPLLMBenchy\Config\SessionSeedConfiguration;

final class AttemptSeedResolver
{
    public function resolve(SessionSeedConfiguration $configuration, int $testIndex, int $runIndex): ?int
    {
        $baseSeed = $configuration->seed;
        if ($baseSeed === null) {
            return null;
        }

        if ($configuration->type === SeedType::Fixed) {
            return $baseSeed;
        }

        $variationIndex = match ($configuration->frequency) {
            SeedFrequency::PerSession => 0,
            SeedFrequency::PerTest => $testIndex,
            SeedFrequency::PerRun => $runIndex,
        };

        if ($variationIndex <= 0) {
            return $baseSeed;
        }

        return match ($configuration->type) {
            SeedType::Iterative => $baseSeed + $variationIndex,
            SeedType::Random => $this->randomizedSeed($baseSeed, $configuration->frequency, $variationIndex),
        };
    }

    private function randomizedSeed(int $baseSeed, SeedFrequency $frequency, int $variationIndex): int
    {
        $hash = (int) sprintf(
            '%u',
            crc32($baseSeed . '|' . $frequency->value . '|' . $variationIndex),
        );

        if ($hash > PHP_INT_MAX) {
            $hash %= PHP_INT_MAX;
        }

        return $hash;
    }
}