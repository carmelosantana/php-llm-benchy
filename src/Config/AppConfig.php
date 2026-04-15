<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPLLMBenchy\Config;

use Dotenv\Dotenv;

final class AppConfig
{
    private static bool $envLoaded = false;

    public function __construct(
        private readonly string $projectRoot,
    ) {
        $this->loadEnv();
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    public function appName(): string
    {
        return $this->string('APP_NAME', 'php-llm-benchy');
    }

    public function appUrl(): string
    {
        return $this->string('APP_URL', 'http://127.0.0.1:8080');
    }

    public function defaultProvider(): string
    {
        return $this->string('DEFAULT_PROVIDER', 'ollama');
    }

    public function ollamaBaseUrl(): string
    {
        return $this->string('OLLAMA_BASE_URL', 'http://ollama:11434/v1');
    }

    public function ollamaNumCtx(): int
    {
        return $this->int('OLLAMA_NUM_CTX', 65536);
    }

    public function defaultSeed(): ?int
    {
        $seed = $this->nullableString('DEFAULT_SEED');

        return $seed === null || $seed === '' ? null : (int) $seed;
    }

    public function defaultRunsPerBenchmark(): int
    {
        return max(1, $this->int('DEFAULT_RUNS_PER_BENCHMARK', 1));
    }

    public function databasePath(): string
    {
        return $this->resolvePath($this->string('DATABASE_PATH', 'storage/benchy.sqlite'));
    }

    public function exportPath(): string
    {
        return $this->resolvePath($this->string('EXPORT_PATH', 'storage/exports'));
    }

    public function sandboxPath(): string
    {
        return $this->resolvePath($this->string('SANDBOX_PATH', 'storage/sandbox'));
    }

    public function supportedProviders(): array
    {
        return [
            [
                'id' => 'ollama',
                'name' => 'Ollama',
                'base_url' => $this->ollamaBaseUrl(),
                'enabled' => true,
            ],
        ];
    }

    public function allowedShellCommands(): array
    {
        return ['pwd', 'ls', 'cat', 'php'];
    }

    public function providerApiKey(string $provider): string
    {
        return match ($provider) {
            'ollama' => 'ollama-local',
            'openai' => $this->string('OPENAI_API_KEY', ''),
            'anthropic' => $this->string('ANTHROPIC_API_KEY', ''),
            'gemini' => $this->string('GEMINI_API_KEY', ''),
            'xai' => $this->string('XAI_API_KEY', ''),
            'mistral' => $this->string('MISTRAL_API_KEY', ''),
            default => '',
        };
    }

    private function loadEnv(): void
    {
        if (self::$envLoaded) {
            return;
        }

        $envPath = $this->projectRoot . '/.env';
        if (file_exists($envPath)) {
            Dotenv::createImmutable($this->projectRoot)->safeLoad();
        }

        self::$envLoaded = true;
    }

    private function string(string $key, string $default = ''): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function nullableString(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($value) ? $value : null;
    }

    private function int(string $key, int $default): int
    {
        return (int) ($this->nullableString($key) ?? (string) $default);
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $this->projectRoot . '/' . ltrim($path, '/');
    }
}