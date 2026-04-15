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