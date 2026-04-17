<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPLLMBenchy\Config;

enum SeedFrequency: string
{
    case PerSession = 'per_session';
    case PerTest = 'per_test';
    case PerRun = 'per_run';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::PerSession => 'Per Session',
            self::PerTest => 'Per Test',
            self::PerRun => 'Per Run',
        };
    }
}