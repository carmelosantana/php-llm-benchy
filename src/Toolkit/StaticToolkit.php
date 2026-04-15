<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPLLMBenchy\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;

final readonly class StaticToolkit implements ToolkitInterface
{
    /**
     * @param ToolInterface[] $tools
     */
    public function __construct(
        private array $tools,
        private string $guidelines,
    ) {}

    public function tools(): array
    {
        return $this->tools;
    }

    public function guidelines(): string
    {
        return $this->guidelines;
    }
}