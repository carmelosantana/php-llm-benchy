<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPLLMBenchy\Runner;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Provider\Response;

final readonly class SeededProvider implements ProviderInterface
{
    /**
     * @param array<string, mixed> $defaultOptions
     */
    public function __construct(
        private ProviderInterface $provider,
        private array $defaultOptions,
    ) {}

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        return $this->provider->chat($messages, $tools, [...$this->defaultOptions, ...$options]);
    }

    public function stream(array $messages, array $tools = [], array $options = []): iterable
    {
        return $this->provider->stream($messages, $tools, [...$this->defaultOptions, ...$options]);
    }

    public function structured(array $messages, string $schema, array $options = []): mixed
    {
        return $this->provider->structured($messages, $schema, [...$this->defaultOptions, ...$options]);
    }

    public function models(): array
    {
        return $this->provider->models();
    }

    public function isAvailable(): bool
    {
        return $this->provider->isAvailable();
    }

    public function getModel(): string
    {
        return $this->provider->getModel();
    }

    public function withModel(string $model): static
    {
        return new self($this->provider->withModel($model), $this->defaultOptions);
    }
}