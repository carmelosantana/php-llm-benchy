<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPLLMBenchy\Toolkit;

use Symfony\Component\Process\Process;

final readonly class SandboxShell
{
    /**
     * @param string[] $allowedCommands
     */
    public function __construct(
        private string $sandboxPath,
        private array $allowedCommands,
    ) {}

    public function sandboxPath(): string
    {
        return $this->sandboxPath;
    }

    public function execute(string $command, string $arguments = ''): array
    {
        $command = trim($command);
        if (!in_array($command, $this->allowedCommands, true)) {
            throw new \RuntimeException(sprintf('Command "%s" is not allowed.', $command));
        }

        $parts = $this->normalizeArguments($command, $arguments);
        $process = new Process(array_merge([$command], $parts), $this->sandboxPath);
        $process->setTimeout(10);
        $process->run();

        return [
            'command' => $command,
            'arguments' => $parts,
            'success' => $process->isSuccessful(),
            'output' => trim($process->getOutput()),
            'error' => trim($process->getErrorOutput()),
            'cwd' => $this->sandboxPath,
        ];
    }

    /**
     * @return string[]
     */
    private function normalizeArguments(string $command, string $arguments): array
    {
        $parts = array_values(array_filter(preg_split('/\s+/', trim($arguments)) ?: [], static fn(string $part): bool => $part !== ''));

        if ($command === 'pwd') {
            return [];
        }

        if ($command === 'php') {
            if (count($parts) !== 2 || $parts[0] !== '-l') {
                throw new \RuntimeException('Only `php -l <file>` is allowed in the sandbox.');
            }

            return ['-l', $this->resolvePath($parts[1])];
        }

        return array_map(fn(string $part): string => $this->looksLikePath($part) ? $this->resolvePath($part) : $part, $parts);
    }

    private function looksLikePath(string $value): bool
    {
        return str_contains($value, '/') || str_contains($value, '.');
    }

    private function resolvePath(string $value): string
    {
        $candidate = str_starts_with($value, '/') ? $value : $this->sandboxPath . '/' . ltrim($value, '/');
        $normalized = realpath(dirname($candidate));
        if ($normalized === false || !str_starts_with($normalized, realpath($this->sandboxPath) ?: $this->sandboxPath)) {
            throw new \RuntimeException('Path escapes the sandbox.');
        }

        return $normalized . '/' . basename($candidate);
    }
}