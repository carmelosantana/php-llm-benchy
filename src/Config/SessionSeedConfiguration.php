<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPLLMBenchy\Config;

final readonly class SessionSeedConfiguration
{
    public function __construct(
        public ?int $seed,
        public SeedType $type,
        public SeedFrequency $frequency,
    ) {}

    /**
     * @return array{seed:int|null, seed_type:string, seed_frequency:string}
     */
    public function toArray(): array
    {
        return [
            'seed' => $this->seed,
            'seed_type' => $this->type->value,
            'seed_frequency' => $this->frequency->value,
        ];
    }
}