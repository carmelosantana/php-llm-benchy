<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPLLMBenchy\Agent;

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;

final class BenchyAgent extends AbstractAgent
{
    /**
     * @param ModelCapability[] $capabilities
     */
    public function __construct(
        ProviderInterface $provider,
        private readonly string $systemInstructions,
        private readonly array $capabilities = [ModelCapability::Text, ModelCapability::Tools],
        int $maxIterations = 8,
    ) {
        parent::__construct($provider, $maxIterations);
    }

    public function instructions(): string
    {
        return $this->systemInstructions;
    }

    public function requiredCapabilities(): array
    {
        return $this->capabilities;
    }
}