<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPLLMBenchy\Benchmark;

final readonly class BenchmarkDefinition
{
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public string $prompt,
        public array $capabilityWeights,
        public array $scenario = [],
        public array $tags = [],
    ) {}

    public function totalCapabilityPoints(): int
    {
        return (int) array_sum($this->capabilityWeights);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'prompt' => $this->prompt,
            'capability_weights' => $this->capabilityWeights,
            'scenario' => $this->scenario,
            'tags' => $this->tags,
        ];
    }
}