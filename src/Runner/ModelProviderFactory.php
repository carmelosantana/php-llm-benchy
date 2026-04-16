<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPLLMBenchy\Runner;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Provider\OllamaProvider;
use CarmeloSantana\PHPLLMBenchy\Config\AppConfig;

final readonly class ModelProviderFactory
{
    /**
     * @var (\Closure(string, string, ?int): ProviderInterface)|null
     */
    public function __construct(
        private AppConfig $config,
        private ?\Closure $resolver = null,
    ) {}

    public function create(string $provider, string $model, ?int $seed = null): ProviderInterface
    {
        if ($this->resolver instanceof \Closure) {
            return ($this->resolver)($provider, $model, $seed);
        }

        $base = match ($provider) {
            'ollama' => new OllamaProvider(
                model: $model,
                baseUrl: $this->config->ollamaBaseUrl(),
                numCtx: $this->config->ollamaNumCtx(),
            ),
            default => throw new \InvalidArgumentException(sprintf('Provider "%s" is not supported in v1.', $provider)),
        };

        if ($seed === null) {
            return $base;
        }

        return new SeededProvider($base, ['seed' => $seed]);
    }

    public function models(string $provider): array
    {
        return array_map(
            static fn(ModelDefinition $model): array => [
                'id' => $model->id,
                'name' => $model->name,
                'provider' => $model->provider,
                'reasoning' => $model->reasoning,
                'context_window' => $model->contextWindow,
                'max_tokens' => $model->maxTokens,
            ],
            $this->create($provider, 'llama3.2')->models(),
        );
    }

    public function isAvailable(string $provider): bool
    {
        return $this->create($provider, 'llama3.2')->isAvailable();
    }
}