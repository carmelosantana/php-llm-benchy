<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPLLMBenchy\Benchmark\BenchmarkRegistry;
use CarmeloSantana\PHPLLMBenchy\Config\AppConfig;
use CarmeloSantana\PHPLLMBenchy\Http\App;
use CarmeloSantana\PHPLLMBenchy\Repository\SessionRepository;
use CarmeloSantana\PHPLLMBenchy\Runner\BenchmarkRunner;
use CarmeloSantana\PHPLLMBenchy\Runner\ModelProviderFactory;
use CarmeloSantana\PHPLLMBenchy\Storage\Database;

it('renders the seed policy controls on the runner page', function (): void {
    $app = testApp();

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/';

    ob_start();
    $app->handle();
    $html = ob_get_clean();

    expect($html)->toContain('Seed Type')
        ->toContain('Seed Change Frequency')
        ->toContain('Generated automatically');
});

it('returns seed policy defaults and options from api config', function (): void {
    $app = testApp();

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/api/config';

    ob_start();
    $app->handle();
    $json = ob_get_clean();
    $payload = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);

    expect($payload['defaults']['seed_type'])->toBe('fixed')
        ->and($payload['defaults']['seed_frequency'])->toBe('per_session')
        ->and(array_column($payload['seed_types'], 'id'))->toBe(['random', 'fixed', 'iterative'])
        ->and(array_column($payload['seed_frequencies'], 'id'))->toBe(['per_session', 'per_test', 'per_run']);
});

function testApp(): App
{
    $databasePath = sys_get_temp_dir() . '/php-llm-benchy-tests/db-' . uniqid('', true) . '.sqlite';
    putenv('DATABASE_PATH=' . $databasePath);
    $_ENV['DATABASE_PATH'] = $databasePath;

    $config = new AppConfig(dirname(__DIR__, 2));
    $database = new Database($config);
    $database->migrate();
    $repository = new SessionRepository($database->pdo());
    $registry = new BenchmarkRegistry();
    $factory = new ModelProviderFactory($config, static fn(string $provider, string $model, ?int $seed): ProviderInterface => new class($model) implements ProviderInterface {
        public function __construct(private readonly string $model) {}

        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            return new Response('{}', ProviderFinishReason::Stop, model: $this->model);
        }

        public function stream(array $messages, array $tools = [], array $options = []): iterable
        {
            yield new Response('ok', ProviderFinishReason::Stop, model: $this->model);
        }

        public function structured(array $messages, string $schema, array $options = []): mixed
        {
            return [];
        }

        public function models(): array
        {
            return [new ModelDefinition(id: $this->model, name: $this->model, provider: 'fake')];
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function getModel(): string
        {
            return $this->model;
        }

        public function withModel(string $model): static
        {
            return new self($model);
        }
    });
    $runner = new BenchmarkRunner($config, $repository, $registry, $factory);

    return new App($config, $repository, $registry, $factory, $runner);
}