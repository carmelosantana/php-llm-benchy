<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPLLMBenchy\Config;

enum SeedType: string
{
    case Random = 'random';
    case Fixed = 'fixed';
    case Iterative = 'iterative';

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
            self::Random => 'Random',
            self::Fixed => 'Fixed',
            self::Iterative => 'Iterative',
        };
    }
}