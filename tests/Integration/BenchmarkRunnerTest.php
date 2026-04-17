<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPLLMBenchy\Benchmark\BenchmarkRegistry;
use CarmeloSantana\PHPLLMBenchy\Config\AppConfig;
use CarmeloSantana\PHPLLMBenchy\Config\SeedFrequency;
use CarmeloSantana\PHPLLMBenchy\Config\SeedType;
use CarmeloSantana\PHPLLMBenchy\Config\SessionSeedConfiguration;
use CarmeloSantana\PHPLLMBenchy\Repository\SessionRepository;
use CarmeloSantana\PHPLLMBenchy\Runner\BenchmarkRunner;
use CarmeloSantana\PHPLLMBenchy\Runner\ModelProviderFactory;
use CarmeloSantana\PHPLLMBenchy\Storage\Database;

it('fails a synthetic mario attempt with a timeout instead of hanging', function (): void {
    $databasePath = sys_get_temp_dir() . '/php-llm-benchy-tests/db-' . uniqid('', true) . '.sqlite';
    if (!is_dir(dirname($databasePath))) {
        mkdir(dirname($databasePath), 0755, true);
    }

    putenv('DATABASE_PATH=' . $databasePath);
    putenv('SYNTHETIC_MARIO_ATTEMPT_TIMEOUT_SECONDS=1');
    $_ENV['DATABASE_PATH'] = $databasePath;
    $_ENV['SYNTHETIC_MARIO_ATTEMPT_TIMEOUT_SECONDS'] = '1';

    $config = new AppConfig(dirname(__DIR__, 2));
    $database = new Database($config);
    $database->migrate();
    $repository = new SessionRepository($database->pdo());
    $registry = new BenchmarkRegistry();
    $factory = new ModelProviderFactory($config, static fn(string $provider, string $model, ?int $seed): ProviderInterface => fakeStreamingProvider($model, 'timeout'));
    $runner = new BenchmarkRunner($config, $repository, $registry, $factory);

    $session = $repository->createSession('ollama', ['fake-model'], 'fake-model', ['mario_speedrun_synthetic'], 1, new SessionSeedConfiguration(7, SeedType::Fixed, SeedFrequency::PerSession));
    $events = [];

    $runner->runSession($session['id'], static function (string $eventType, array $payload) use (&$events): void {
        $events[] = [$eventType, $payload];
    });

    $stored = $repository->getSession($session['id']);

    expect($stored)->not->toBeNull();
    if ($stored === null) {
        throw new RuntimeException('Expected stored session.');
    }

    expect($stored['attempts'])->toHaveCount(1)
        ->and($stored['attempts'][0]['status'])->toBe('failed')
        ->and($stored['attempts'][0]['metrics']['control_reason'])->toBe('timeout')
        ->and($stored['attempts'][0]['metrics']['timeout_seconds'])->toBe(1)
        ->and(hasEvent($events, 'attempt_failed'))->toBeTrue();
});

it('pauses and resumes a session between attempts without duplicating work', function (): void {
    $databasePath = sys_get_temp_dir() . '/php-llm-benchy-tests/db-' . uniqid('', true) . '.sqlite';
    if (!is_dir(dirname($databasePath))) {
        mkdir(dirname($databasePath), 0755, true);
    }

    putenv('DATABASE_PATH=' . $databasePath);
    $_ENV['DATABASE_PATH'] = $databasePath;

    $config = new AppConfig(dirname(__DIR__, 2));
    $database = new Database($config);
    $database->migrate();
    $repository = new SessionRepository($database->pdo());
    $registry = new BenchmarkRegistry();
    $factory = new ModelProviderFactory($config, static fn(string $provider, string $model, ?int $seed): ProviderInterface => fakeStreamingProvider($model, 'text'));

    $pauseWaiterCalls = 0;
    $runner = new BenchmarkRunner(
        $config,
        $repository,
        $registry,
        $factory,
        function (int $microseconds) use ($repository, &$pauseWaiterCalls): void {
            $pauseWaiterCalls++;

            foreach ($repository->listSessions() as $session) {
                if (($session['status'] ?? '') === 'paused') {
                    $repository->markSessionResumed((string) $session['id']);
                }
            }
        },
    );

    $session = $repository->createSession('ollama', ['fake-model'], 'fake-model', ['creative_story', 'poem'], 1, new SessionSeedConfiguration(7, SeedType::Fixed, SeedFrequency::PerSession));
    $paused = false;
    $events = [];

    $runner->runSession($session['id'], function (string $eventType, array $payload) use (&$paused, $repository, $session, &$events): void {
        $events[] = [$eventType, $payload];

        if ($eventType === 'attempt_captured' && !$paused) {
            $paused = true;
            $repository->markSessionPaused($session['id']);
        }
    });

    $stored = $repository->getSession($session['id']);

    expect($stored)->not->toBeNull();
    if ($stored === null) {
        throw new RuntimeException('Expected stored session.');
    }

    expect($pauseWaiterCalls)->toBeGreaterThan(0)
        ->and($stored['status'])->toBe('completed')
        ->and($stored['attempts'])->toHaveCount(2)
        ->and(hasEvent($events, 'session_paused'))->toBeTrue()
        ->and(hasEvent($events, 'session_resumed'))->toBeTrue();
});

it('stops a session after capture and skips evaluation', function (): void {
    $databasePath = sys_get_temp_dir() . '/php-llm-benchy-tests/db-' . uniqid('', true) . '.sqlite';
    if (!is_dir(dirname($databasePath))) {
        mkdir(dirname($databasePath), 0755, true);
    }

    putenv('DATABASE_PATH=' . $databasePath);
    $_ENV['DATABASE_PATH'] = $databasePath;

    $config = new AppConfig(dirname(__DIR__, 2));
    $database = new Database($config);
    $database->migrate();
    $repository = new SessionRepository($database->pdo());
    $registry = new BenchmarkRegistry();
    $factory = new ModelProviderFactory($config, static fn(string $provider, string $model, ?int $seed): ProviderInterface => fakeStreamingProvider($model, 'text'));
    $runner = new BenchmarkRunner($config, $repository, $registry, $factory);

    $session = $repository->createSession('ollama', ['fake-model'], 'fake-model', ['creative_story'], 1, new SessionSeedConfiguration(7, SeedType::Fixed, SeedFrequency::PerSession));

    $runner->runSession($session['id'], function (string $eventType, array $payload) use ($repository, $session): void {
        if ($eventType === 'attempt_captured') {
            $repository->markSessionStopped($session['id'], 'Stopped by user.');
        }
    });

    $stored = $repository->getSession($session['id']);

    expect($stored)->not->toBeNull();
    if ($stored === null) {
        throw new RuntimeException('Expected stored session.');
    }

    expect($stored['status'])->toBe('stopped')
        ->and($stored['attempts'])->toHaveCount(1)
        ->and($stored['attempts'][0]['status'])->toBe('captured')
        ->and($stored['benchmark_scores'])->toHaveCount(0)
        ->and($stored['model_scores'])->toHaveCount(0);
});

it('uses iterative per-run seeds for attempts and keeps evaluation on the base seed', function (): void {
    $databasePath = sys_get_temp_dir() . '/php-llm-benchy-tests/db-' . uniqid('', true) . '.sqlite';
    if (!is_dir(dirname($databasePath))) {
        mkdir(dirname($databasePath), 0755, true);
    }

    putenv('DATABASE_PATH=' . $databasePath);
    $_ENV['DATABASE_PATH'] = $databasePath;

    $config = new AppConfig(dirname(__DIR__, 2));
    $database = new Database($config);
    $database->migrate();
    $repository = new SessionRepository($database->pdo());
    $registry = new BenchmarkRegistry();
    $providerCalls = [];
    $factory = new ModelProviderFactory(
        $config,
        static function (string $provider, string $model, ?int $seed) use (&$providerCalls): ProviderInterface {
            $providerCalls[] = ['provider' => $provider, 'model' => $model, 'seed' => $seed];

            return fakeStreamingProvider($model, 'text');
        },
    );
    $runner = new BenchmarkRunner($config, $repository, $registry, $factory);

    $session = $repository->createSession(
        'ollama',
        ['fake-model'],
        'judge-model',
        ['creative_story', 'poem'],
        2,
        new SessionSeedConfiguration(7, SeedType::Iterative, SeedFrequency::PerRun),
    );

    $events = [];
    $runner->runSession($session['id'], static function (string $eventType, array $payload) use (&$events): void {
        $events[] = [$eventType, $payload];
    });

    $stored = $repository->getSession($session['id']);

    expect($stored)->not->toBeNull();
    if ($stored === null) {
        throw new RuntimeException('Expected stored session.');
    }

    expect(array_column($stored['attempts'], 'effective_seed'))->toBe([7, 8, 9, 10]);

    $attemptStartSeeds = array_map(
        static fn(array $event): int|null => $event[1]['payload']['seed'] ?? null,
        array_values(array_filter($events, static fn(array $event): bool => $event[0] === 'attempt_start')),
    );

    expect($attemptStartSeeds)->toBe([7, 8, 9, 10]);
    expect($providerCalls)->toHaveCount(5)
        ->and(array_column(array_filter($providerCalls, static fn(array $call): bool => $call['model'] === 'fake-model'), 'seed'))->toBe([7, 8, 9, 10])
        ->and(array_column(array_filter($providerCalls, static fn(array $call): bool => $call['model'] === 'judge-model'), 'seed'))->toBe([7]);
});

function fakeStreamingProvider(string $model, string $mode): ProviderInterface
{
    return new class($model, $mode) implements ProviderInterface {
        public function __construct(
            private readonly string $model,
            private readonly string $mode,
        ) {}

        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            throw new RuntimeException('Not used in this test.');
        }

        public function stream(array $messages, array $tools = [], array $options = []): iterable
        {
            if ($this->mode === 'timeout') {
                while (true) {
                    usleep(20_000);
                    yield new Response('', ProviderFinishReason::Stop, model: $this->model);
                }
            }

            yield new Response('A valid benchmark response.', ProviderFinishReason::Stop, model: $this->model);
        }

        public function structured(array $messages, string $schema, array $options = []): mixed
        {
            throw new RuntimeException('Not used in this test.');
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
            return new self($model, $this->mode);
        }
    };
}

function hasEvent(array $events, string $eventType): bool
{
    foreach ($events as $event) {
        if (($event[0] ?? null) === $eventType) {
            return true;
        }
    }

    return false;
}