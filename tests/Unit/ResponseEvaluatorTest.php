<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPLLMBenchy\Benchmark\BenchmarkDefinition;
use CarmeloSantana\PHPLLMBenchy\Benchmark\BenchmarkRegistry;
use CarmeloSantana\PHPLLMBenchy\Config\AppConfig;
use CarmeloSantana\PHPLLMBenchy\Evaluation\ResponseEvaluator;

it('awards a full capability score for a valid fizzbuzz implementation', function (): void {
    $sandboxPath = sys_get_temp_dir() . '/php-llm-benchy-tests/sandbox-' . uniqid('', true);
    if (!is_dir($sandboxPath)) {
        mkdir($sandboxPath, 0755, true);
    }

    putenv('SANDBOX_PATH=' . $sandboxPath);
    $_ENV['SANDBOX_PATH'] = $sandboxPath;

    $config = new AppConfig(dirname(__DIR__, 2));
    $provider = new class implements ProviderInterface {
        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            return new Response('{}', ProviderFinishReason::Stop);
        }

        public function stream(array $messages, array $tools = [], array $options = []): iterable
        {
            return [];
        }

        public function structured(array $messages, string $schema, array $options = []): mixed
        {
            return null;
        }

        public function models(): array
        {
            return [];
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function getModel(): string
        {
            return 'judge';
        }

        public function withModel(string $model): static
        {
            return $this;
        }
    };

    $registry = new BenchmarkRegistry();
    $benchmark = $registry->find('php_script');
    expect($benchmark)->not->toBeNull();
    /** @var BenchmarkDefinition $benchmark */
    $evaluator = new ResponseEvaluator($provider, $config);

    $response = <<<'PHP'
<?php

function benchy_fizzbuzz(int $n): array
{
    $output = [];

    for ($i = 1; $i <= $n; $i++) {
        $value = '';

        if ($i % 3 === 0) {
            $value .= 'Fizz';
        }

        if ($i % 5 === 0) {
            $value .= 'Buzz';
        }

        $output[] = $value === '' ? (string) $i : $value;
    }

    return $output;
}
PHP;

    $result = $evaluator->scoreCapability($benchmark, $response, []);

    expect($result['score'])->toBe(50.0)
        ->and($result['checks']['instruction_compliance'])->toBeTrue()
        ->and($result['checks']['lint_success'])->toBeTrue()
        ->and($result['checks']['behavior_matches_expected'])->toBeTrue();
});

it('rewards correct multi-tool coverage and synthesis', function (): void {
    $sandboxPath = sys_get_temp_dir() . '/php-llm-benchy-tests/sandbox-' . uniqid('', true);
    if (!is_dir($sandboxPath)) {
        mkdir($sandboxPath, 0755, true);
    }

    putenv('SANDBOX_PATH=' . $sandboxPath);
    $_ENV['SANDBOX_PATH'] = $sandboxPath;

    $config = new AppConfig(dirname(__DIR__, 2));
    $provider = new class implements ProviderInterface {
        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            return new Response('{}', ProviderFinishReason::Stop);
        }

        public function stream(array $messages, array $tools = [], array $options = []): iterable
        {
            return [];
        }

        public function structured(array $messages, string $schema, array $options = []): mixed
        {
            return null;
        }

        public function models(): array
        {
            return [];
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function getModel(): string
        {
            return 'judge';
        }

        public function withModel(string $model): static
        {
            return $this;
        }
    };

    $registry = new BenchmarkRegistry();
    $benchmark = $registry->find('concurrent_tool_use');
    expect($benchmark)->not->toBeNull();
    /** @var BenchmarkDefinition $benchmark */
    $evaluator = new ResponseEvaluator($provider, $config);

    $result = $evaluator->scoreCapability($benchmark, 'Lisbon has population 504718, runs on Europe/Lisbon, and the weather is Sunny 22C.', [
        'tool_calls' => [
            ['name' => 'lookup_population', 'arguments' => ['city' => 'Lisbon']],
            ['name' => 'lookup_timezone', 'arguments' => ['city' => 'Lisbon']],
            ['name' => 'lookup_weather', 'arguments' => ['city' => 'Lisbon']],
        ],
        'tool_names' => ['lookup_population', 'lookup_timezone', 'lookup_weather'],
    ]);

    expect($result['score'])->toBe(50.0)
        ->and($result['checks']['required_tools_called'])->toBe(3)
        ->and($result['checks']['city_arguments_correct'])->toBe(3)
        ->and($result['checks']['synthesis_fragments_found'])->toBe(3);
});

it('scores a completed synthetic mario run based on progress, time, and valid tool use', function (): void {
    $sandboxPath = sys_get_temp_dir() . '/php-llm-benchy-tests/sandbox-' . uniqid('', true);
    if (!is_dir($sandboxPath)) {
        mkdir($sandboxPath, 0755, true);
    }

    putenv('SANDBOX_PATH=' . $sandboxPath);
    $_ENV['SANDBOX_PATH'] = $sandboxPath;

    $config = new AppConfig(dirname(__DIR__, 2));
    $provider = new class implements ProviderInterface {
        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            return new Response('{}', ProviderFinishReason::Stop);
        }

        public function stream(array $messages, array $tools = [], array $options = []): iterable
        {
            return [];
        }

        public function structured(array $messages, string $schema, array $options = []): mixed
        {
            return null;
        }

        public function models(): array
        {
            return [];
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function getModel(): string
        {
            return 'judge';
        }

        public function withModel(string $model): static
        {
            return $this;
        }
    };

    $registry = new BenchmarkRegistry();
    $benchmark = $registry->find('mario_speedrun_synthetic');
    expect($benchmark)->not->toBeNull();
    /** @var BenchmarkDefinition $benchmark */
    $evaluator = new ResponseEvaluator($provider, $config);

    $result = $evaluator->scoreCapability($benchmark, 'Completed in 230 synthetic frames.', [
        'tool_names' => ['read_game_state', 'press_buttons', 'wait_frames'],
        'synthetic_mario' => [
            'completed' => true,
            'failed' => false,
            'frames_used' => 230,
            'target_frames' => 260,
            'max_frames' => 480,
            'checkpoints_cleared' => 5,
            'checkpoint_count' => 5,
            'reads' => 3,
            'actions' => 4,
            'invalid_actions' => 0,
            'deaths' => 0,
            'failure_reason' => null,
        ],
    ]);

    expect($result['score'])->toBeGreaterThan(45.0)
        ->and($result['checks']['completed'])->toBeTrue()
        ->and($result['checks']['required_tools_called'])->toBe(3)
        ->and($result['checks']['invalid_actions'])->toBe(0);
});

it('keeps synthetic mario failures well below a passing score', function (): void {
    $sandboxPath = sys_get_temp_dir() . '/php-llm-benchy-tests/sandbox-' . uniqid('', true);
    if (!is_dir($sandboxPath)) {
        mkdir($sandboxPath, 0755, true);
    }

    putenv('SANDBOX_PATH=' . $sandboxPath);
    $_ENV['SANDBOX_PATH'] = $sandboxPath;

    $config = new AppConfig(dirname(__DIR__, 2));
    $provider = new class implements ProviderInterface {
        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            return new Response('{}', ProviderFinishReason::Stop);
        }

        public function stream(array $messages, array $tools = [], array $options = []): iterable
        {
            return [];
        }

        public function structured(array $messages, string $schema, array $options = []): mixed
        {
            return null;
        }

        public function models(): array
        {
            return [];
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function getModel(): string
        {
            return 'judge';
        }

        public function withModel(string $model): static
        {
            return $this;
        }
    };

    $registry = new BenchmarkRegistry();
    $benchmark = $registry->find('mario_speedrun_synthetic');
    expect($benchmark)->not->toBeNull();
    /** @var BenchmarkDefinition $benchmark */
    $evaluator = new ResponseEvaluator($provider, $config);

    $result = $evaluator->scoreCapability($benchmark, 'Mario crashed into the first Goomba.', [
        'tool_names' => ['press_buttons'],
        'synthetic_mario' => [
            'completed' => false,
            'failed' => true,
            'frames_used' => 16,
            'target_frames' => 260,
            'max_frames' => 480,
            'checkpoints_cleared' => 0,
            'checkpoint_count' => 5,
            'reads' => 0,
            'actions' => 1,
            'invalid_actions' => 1,
            'deaths' => 1,
            'failure_reason' => 'collision_with_goomba',
        ],
    ]);

    expect($result['score'])->toBeLessThan(10.0)
        ->and($result['checks']['completed'])->toBeFalse()
        ->and($result['checks']['failure_reason'])->toBe('collision_with_goomba');
});