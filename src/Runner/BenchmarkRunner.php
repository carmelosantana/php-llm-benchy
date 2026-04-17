<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPLLMBenchy\Runner;

use BackedEnum;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPLLMBenchy\Agent\BenchyAgent;
use CarmeloSantana\PHPLLMBenchy\Benchmark\BenchmarkDefinition;
use CarmeloSantana\PHPLLMBenchy\Benchmark\BenchmarkRegistry;
use CarmeloSantana\PHPLLMBenchy\Benchmark\SyntheticMarioBenchmarkFixture;
use CarmeloSantana\PHPLLMBenchy\Config\AppConfig;
use CarmeloSantana\PHPLLMBenchy\Config\SeedFrequency;
use CarmeloSantana\PHPLLMBenchy\Config\SeedType;
use CarmeloSantana\PHPLLMBenchy\Config\SessionSeedConfiguration;
use CarmeloSantana\PHPLLMBenchy\Evaluation\ResponseEvaluator;
use CarmeloSantana\PHPLLMBenchy\Repository\SessionRepository;
use CarmeloSantana\PHPLLMBenchy\Toolkit\BenchmarkTelemetryAwareToolkit;
use CarmeloSantana\PHPLLMBenchy\Toolkit\SandboxShell;
use CarmeloSantana\PHPLLMBenchy\Toolkit\StaticToolkit;
use CarmeloSantana\PHPLLMBenchy\Toolkit\SyntheticMarioToolkit;

final class BenchmarkRunner
{
    /**
     * @var \Closure(int): void
     */
    private readonly \Closure $pauseWaiter;

    private readonly AttemptSeedResolver $seedResolver;

    public function __construct(
        private readonly AppConfig $config,
        private readonly SessionRepository $repository,
        private readonly BenchmarkRegistry $registry,
        private readonly ModelProviderFactory $providerFactory,
        ?\Closure $pauseWaiter = null,
        ?AttemptSeedResolver $seedResolver = null,
    ) {
        $this->pauseWaiter = $pauseWaiter ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
        $this->seedResolver = $seedResolver ?? new AttemptSeedResolver();
    }

    /**
     * @param \Closure(string, array<string, mixed>): void $stream
     */
    public function runSession(string $sessionId, \Closure $stream): void
    {
        $session = $this->repository->getSession($sessionId);
        if ($session === null) {
            throw new \RuntimeException('Session not found.');
        }

        if (in_array((string) ($session['status'] ?? ''), ['completed', 'stopped'], true)) {
            $this->emit($stream, $sessionId, null, 'session_skipped', ['reason' => 'Session already finished.']);

            return;
        }

        if (($session['status'] ?? '') === 'draft') {
            $this->prepareSandbox($sessionId);
        }

        if (($session['status'] ?? '') !== 'running') {
            $this->repository->markSessionRunning($sessionId);
        }

        $attemptPayloads = [];
        $testIndex = 0;
        $runIndex = 0;
        $seedConfiguration = $this->seedConfigurationFromSession($session);

        try {
            foreach ($session['models'] as $modelIndex => $modelRow) {
                if (!$this->waitForRunnableSession($sessionId, $stream)) {
                    return;
                }

                $modelId = (string) $modelRow['model_id'];
                $this->emit($stream, $sessionId, null, 'model_start', ['model_id' => $modelId]);

                foreach ($session['benchmarks'] as $benchmarkIndex => $benchmarkRow) {
                    if (!$this->waitForRunnableSession($sessionId, $stream)) {
                        return;
                    }

                    $benchmarkId = (string) $benchmarkRow['benchmark_id'];
                    $definition = $this->registry->find($benchmarkId);

                    if (!$definition instanceof BenchmarkDefinition) {
                        continue;
                    }

                    $runs = (int) $session['runs_per_benchmark'];
                    for ($runNumber = 1; $runNumber <= $runs; $runNumber++) {
                        if (!$this->waitForRunnableSession($sessionId, $stream)) {
                            return;
                        }

                        $effectiveSeed = $this->seedResolver->resolve($seedConfiguration, $testIndex, $runIndex);

                        $attemptPayloads[] = $this->runAttempt(
                            $sessionId,
                            (string) $session['provider'],
                            $modelId,
                            $definition,
                            $runNumber,
                            $effectiveSeed,
                            $stream,
                        );

                        $runIndex++;
                    }

                    $testIndex++;
                }
            }

            if (!$this->waitForRunnableSession($sessionId, $stream)) {
                return;
            }

            $this->repository->markSessionEvaluating($sessionId);
            $this->emit($stream, $sessionId, null, 'evaluation_start', ['message' => 'Scoring captured responses']);
            if (!$this->evaluateAttempts($sessionId, (string) $session['provider'], (string) $session['evaluation_model'], $seedConfiguration->seed, $attemptPayloads, $stream)) {
                if ($this->repository->sessionStatus($sessionId) !== 'stopped') {
                    $this->repository->markSessionStopped($sessionId, 'Session stopped before evaluation completed.');
                }
                $this->emit($stream, $sessionId, null, 'session_stopped', ['message' => 'Session stopped before evaluation completed.']);

                return;
            }
            $this->repository->markSessionCompleted($sessionId);
            $this->emit($stream, $sessionId, null, 'session_complete', ['leaderboard' => $this->repository->listModelScores($sessionId)]);
        } catch (\Throwable $e) {
            $this->repository->markSessionFailed($sessionId, $e->getMessage());
            $this->emit($stream, $sessionId, null, 'session_failed', ['message' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * @param \Closure(string, array<string, mixed>): void $stream
     * @return array<string, mixed>
     */
    private function runAttempt(string $sessionId, string $providerName, string $modelId, BenchmarkDefinition $benchmark, int $runNumber, ?int $seed, \Closure $stream): array
    {
        $attemptId = $this->repository->createAttempt($sessionId, $modelId, $benchmark->id, $runNumber, $benchmark->prompt, $seed);
        $this->emit($stream, $sessionId, $attemptId, 'attempt_start', [
            'model_id' => $modelId,
            'benchmark_id' => $benchmark->id,
            'run_number' => $runNumber,
            'prompt' => $benchmark->prompt,
            'seed' => $seed,
        ]);

        $provider = $this->providerFactory->create($providerName, $modelId, $seed);
        $observer = new AttemptObserver(function (string $eventType, array $payload) use ($stream, $sessionId, $attemptId): void {
            $this->emit($stream, $sessionId, $attemptId, $eventType, $payload);
        });

        $attemptTimeout = $benchmark->id === SyntheticMarioBenchmarkFixture::ID
            ? $this->config->syntheticMarioAttemptTimeoutSeconds()
            : null;
        $token = new AttemptCancellationToken(
            $this->repository,
            $sessionId,
            $attemptTimeout !== null ? microtime(true) + $attemptTimeout : null,
            $this->config->sessionControlPollMilliseconds(),
        );

        $agent = new BenchyAgent(
            provider: $provider,
            systemInstructions: $this->instructionsForBenchmark($benchmark),
            capabilities: $this->capabilitiesForBenchmark($benchmark),
            maxIterations: $this->maxIterationsForBenchmark($benchmark),
            cancellationToken: $token,
        );
        $agent->attach($observer);

        $toolkit = $this->toolkitForBenchmark($sessionId, $benchmark);
        if ($toolkit !== null) {
            $agent->addToolkit($toolkit);
        }

        $history = $this->historyForBenchmark($benchmark);

        try {
            $output = $agent->run(new UserMessage($benchmark->prompt), $history);
            $usage = $output->usage;
            $usageData = $usage === null ? [] : [
                'prompt_tokens' => $usage->promptTokens,
                'completion_tokens' => $usage->completionTokens,
                'total_tokens' => $usage->totalTokens,
            ];
            $metrics = $observer->metrics();
            if ($toolkit instanceof BenchmarkTelemetryAwareToolkit) {
                $metrics = array_merge($metrics, $toolkit->benchmarkMetrics());
            }
            $metrics['iterations'] = $output->iterations;
            $metrics['finish_reason'] = $output->finishReason->value;
            $metrics['resolved_model'] = $output->model !== '' ? $output->model : $modelId;

            if ($output->finishReason === AgentFinishReason::Error && $token->reason() !== null) {
                $failureMessage = $token->reason() === 'timeout'
                    ? sprintf('Synthetic Mario attempt timed out after %d seconds.', $attemptTimeout ?? 0)
                    : 'Attempt stopped by user.';

                $metrics['control_reason'] = $token->reason();
                $metrics['timeout_seconds'] = $attemptTimeout;

                $this->repository->completeAttempt(
                    $attemptId,
                    'failed',
                    '',
                    $observer->reasoning(),
                    ['error' => $failureMessage],
                    [],
                    $usageData,
                    $metrics,
                    0.0,
                    0.0,
                    0.0,
                );

                $this->emit($stream, $sessionId, $attemptId, 'attempt_failed', [
                    'message' => $failureMessage,
                    'reason' => $token->reason(),
                ]);

                return [
                    'attempt_id' => $attemptId,
                    'model_id' => $modelId,
                    'benchmark_id' => $benchmark->id,
                    'run_number' => $runNumber,
                    'effective_seed' => $seed,
                    'response_text' => '',
                    'capability_score' => 0.0,
                    'metrics' => $metrics,
                    'definition' => $benchmark,
                ];
            }

            $evaluator = new ResponseEvaluator($provider, $this->config);
            $capability = $evaluator->scoreCapability($benchmark, $output->content, $metrics);

            $this->repository->completeAttempt(
                $attemptId,
                'captured',
                $output->content,
                $observer->reasoning(),
                $capability['checks'],
                [],
                $usageData,
                $metrics,
                (float) $capability['score'],
                0.0,
                0.0,
            );

            $this->emit($stream, $sessionId, $attemptId, 'attempt_captured', [
                'capability_score' => $capability['score'],
                'iterations' => $output->iterations,
                'seed' => $seed,
            ]);

            return [
                'attempt_id' => $attemptId,
                'model_id' => $modelId,
                'benchmark_id' => $benchmark->id,
                'run_number' => $runNumber,
                'effective_seed' => $seed,
                'response_text' => $output->content,
                'capability_score' => (float) $capability['score'],
                'metrics' => $metrics,
                'definition' => $benchmark,
            ];
        } catch (\Throwable $e) {
            $this->repository->completeAttempt(
                $attemptId,
                'failed',
                '',
                $observer->reasoning(),
                ['error' => $e->getMessage()],
                [],
                [],
                array_merge($observer->metrics(), ['error' => $e->getMessage()]),
                0.0,
                0.0,
                0.0,
            );
            $this->emit($stream, $sessionId, $attemptId, 'attempt_failed', ['message' => $e->getMessage()]);

            return [
                'attempt_id' => $attemptId,
                'model_id' => $modelId,
                'benchmark_id' => $benchmark->id,
                'run_number' => $runNumber,
                'effective_seed' => $seed,
                'response_text' => '',
                'capability_score' => 0.0,
                'metrics' => ['error' => $e->getMessage()],
                'definition' => $benchmark,
            ];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $attemptPayloads
     * @param \Closure(string, array<string, mixed>): void $stream
     */
    private function evaluateAttempts(string $sessionId, string $providerName, string $evaluationModel, ?int $seed, array $attemptPayloads, \Closure $stream): bool
    {
        $provider = $this->providerFactory->create($providerName, $evaluationModel, $seed);
        $evaluator = new ResponseEvaluator($provider, $this->config);
        $evaluated = [];

        foreach ($attemptPayloads as $payload) {
            if (!$this->waitForRunnableSession($sessionId, $stream, allowPause: false)) {
                return false;
            }

            /** @var BenchmarkDefinition $definition */
            $definition = $payload['definition'];
            $attemptId = (string) $payload['attempt_id'];
            $responseText = (string) $payload['response_text'];
            $capabilityScore = (float) $payload['capability_score'];

            if ($responseText === '') {
                $this->repository->updateAttemptScores($attemptId, [], 0.0, 0.0, 'failed');
                $evaluated[] = array_merge($payload, ['quality_score' => 0.0, 'total_score' => 0.0, 'rubric' => []]);
                continue;
            }

            $rubric = $evaluator->scoreQuality($definition, $responseText);
            $qualityScore = (float) ($rubric['total'] ?? 0.0);
            $totalScore = max(0.0, min(100.0, $capabilityScore + $qualityScore));
            $this->repository->updateAttemptScores($attemptId, $rubric, $qualityScore, $totalScore);
            $this->emit($stream, $sessionId, $attemptId, 'attempt_scored', [
                'quality_score' => $qualityScore,
                'total_score' => $totalScore,
                'rubric' => $rubric,
            ]);

            $evaluated[] = array_merge($payload, [
                'quality_score' => $qualityScore,
                'total_score' => $totalScore,
                'rubric' => $rubric,
            ]);
        }

        $weights = [];
        foreach ($this->repository->listSessionBenchmarks($sessionId) as $benchmarkRow) {
            $weights[(string) $benchmarkRow['benchmark_id']] = (float) $benchmarkRow['weight'];
        }

        $benchmarkBuckets = [];
        foreach ($evaluated as $payload) {
            $key = $payload['model_id'] . '|' . $payload['benchmark_id'];
            $benchmarkBuckets[$key][] = $payload;
        }

        $modelBenchmarkScores = [];
        foreach ($benchmarkBuckets as $key => $bucket) {
            [$modelId, $benchmarkId] = explode('|', $key, 2);
            $total = array_sum(array_map(static fn(array $item): float => (float) $item['total_score'], $bucket));
            $capability = array_sum(array_map(static fn(array $item): float => (float) $item['capability_score'], $bucket));
            $quality = array_sum(array_map(static fn(array $item): float => (float) $item['quality_score'], $bucket));
            $runs = count($bucket);

            $average = round($total / max(1, $runs), 2);
            $capabilityAverage = round($capability / max(1, $runs), 2);
            $qualityAverage = round($quality / max(1, $runs), 2);
            $this->repository->replaceBenchmarkScore($sessionId, $modelId, $benchmarkId, $average, $capabilityAverage, $qualityAverage, $runs, [
                'attempt_ids' => array_map(static fn(array $item): string => (string) $item['attempt_id'], $bucket),
            ]);
            $modelBenchmarkScores[$modelId][$benchmarkId] = $average;
        }

        foreach ($modelBenchmarkScores as $modelId => $scores) {
            $weightedScore = 0.0;
            $totalWeight = 0.0;
            foreach ($scores as $benchmarkId => $average) {
                $weight = $weights[$benchmarkId] ?? 1.0;
                $weightedScore += $average * $weight;
                $totalWeight += $weight;
            }
            $overall = $totalWeight > 0 ? round($weightedScore / $totalWeight, 2) : 0.0;
            $runs = count(array_filter($evaluated, static fn(array $item): bool => $item['model_id'] === $modelId));
            $this->repository->replaceModelScore($sessionId, $modelId, $overall, count($scores), $runs, [
                'weights' => $weights,
            ]);
        }

        return true;
    }

    private function instructionsForBenchmark(BenchmarkDefinition $benchmark): string
    {
        return match ($benchmark->id) {
            'php_script' => 'You are running inside a benchmark harness. Follow the user prompt exactly. When asked for PHP, output only PHP and no markdown fences.',
            'memory_recall' => 'You are running inside a benchmark harness. Use only the prior conversation and the user prompt. Do not invent details.',
            SyntheticMarioBenchmarkFixture::ID => 'You are running inside a benchmark harness that simulates a Mario control loop. Read game state before acting, use the synthetic Mario tools to clear the course as fast as possible, and finish with a short summary that states whether the run completed and the total synthetic frames used.',
            default => 'You are running inside a benchmark harness. Follow the user prompt exactly, use tools when they are useful, and avoid extra meta commentary.',
        };
    }

    private function capabilitiesForBenchmark(BenchmarkDefinition $benchmark): array
    {
        return in_array($benchmark->id, ['tool_use', 'concurrent_tool_use', 'shell_execution', SyntheticMarioBenchmarkFixture::ID], true)
            ? [ModelCapability::Text, ModelCapability::Tools]
            : [ModelCapability::Text];
    }

    private function maxIterationsForBenchmark(BenchmarkDefinition $benchmark): int
    {
        return match ($benchmark->id) {
            SyntheticMarioBenchmarkFixture::ID => (int) ($benchmark->scenario['max_iterations'] ?? 14),
            'tool_use', 'concurrent_tool_use', 'shell_execution' => 8,
            default => 5,
        };
    }

    private function historyForBenchmark(BenchmarkDefinition $benchmark): ?Conversation
    {
        if ($benchmark->id !== 'memory_recall') {
            return null;
        }

        $conversation = new Conversation();
        foreach ($benchmark->scenario['history'] as $message) {
            if (($message['role'] ?? '') === 'assistant') {
                $conversation->add(new AssistantMessage((string) $message['content']));
                continue;
            }

            $conversation->add(new UserMessage((string) $message['content']));
        }

        return $conversation;
    }

    private function toolkitForBenchmark(string $sessionId, BenchmarkDefinition $benchmark): ?ToolkitInterface
    {
        return match ($benchmark->id) {
            'tool_use' => $this->toolUseToolkit(),
            'concurrent_tool_use' => $this->concurrentToolkit(),
            'shell_execution' => $this->shellToolkit($sessionId, $benchmark),
            SyntheticMarioBenchmarkFixture::ID => new SyntheticMarioToolkit($benchmark->scenario),
            default => null,
        };
    }

    private function toolUseToolkit(): StaticToolkit
    {
        $tool = new Tool(
            name: 'add_numbers',
            description: 'Add a comma-separated list of integers and return the total.',
            parameters: [
                new StringParameter('numbers', 'Comma-separated integers, for example: 17,25,8', required: true),
            ],
            callback: function (array $args): ToolResult {
                $numbers = array_map('intval', array_filter(array_map('trim', explode(',', (string) ($args['numbers'] ?? ''))), static fn(string $item): bool => $item !== ''));
                return ToolResult::success(json_encode([
                    'numbers' => $numbers,
                    'total' => array_sum($numbers),
                ], JSON_UNESCAPED_SLASHES) ?: '{}');
            },
        );

        return new StaticToolkit([$tool], 'Use add_numbers when the task asks you to compute the provided numbers.');
    }

    private function concurrentToolkit(): StaticToolkit
    {
        $population = new Tool(
            name: 'lookup_population',
            description: 'Look up a city population from the benchmark fixture data.',
            parameters: [new StringParameter('city', 'City name', required: true)],
            callback: fn(array $args): ToolResult => ToolResult::success(json_encode([
                'city' => $args['city'],
                'population' => strtolower((string) $args['city']) === 'lisbon' ? '504718' : 'unknown',
            ], JSON_UNESCAPED_SLASHES) ?: '{}'),
        );
        $timezone = new Tool(
            name: 'lookup_timezone',
            description: 'Look up a city timezone from the benchmark fixture data.',
            parameters: [new StringParameter('city', 'City name', required: true)],
            callback: fn(array $args): ToolResult => ToolResult::success(json_encode([
                'city' => $args['city'],
                'timezone' => strtolower((string) $args['city']) === 'lisbon' ? 'Europe/Lisbon' : 'unknown',
            ], JSON_UNESCAPED_SLASHES) ?: '{}'),
        );
        $weather = new Tool(
            name: 'lookup_weather',
            description: 'Look up a city weather summary from the benchmark fixture data.',
            parameters: [new StringParameter('city', 'City name', required: true)],
            callback: fn(array $args): ToolResult => ToolResult::success(json_encode([
                'city' => $args['city'],
                'weather' => strtolower((string) $args['city']) === 'lisbon' ? 'Sunny 22C' : 'unknown',
            ], JSON_UNESCAPED_SLASHES) ?: '{}'),
        );

        return new StaticToolkit(
            [$population, $timezone, $weather],
            'Use the city lookup tools to gather all requested facts before answering. Do not guess missing values.',
        );
    }

    private function shellToolkit(string $sessionId, BenchmarkDefinition $benchmark): StaticToolkit
    {
        $sandboxPath = $this->config->sandboxPath() . '/' . $sessionId;
        if (!is_dir($sandboxPath)) {
            mkdir($sandboxPath, 0755, true);
        }

        $noteFile = $sandboxPath . '/' . (string) $benchmark->scenario['note_file'];
        file_put_contents($noteFile, (string) $benchmark->scenario['note_contents']);
        $shell = new SandboxShell($sandboxPath, $this->config->allowedShellCommands());

        $tool = new Tool(
            name: 'run_shell_command',
            description: 'Run a safe shell command inside the benchmark sandbox. Allowed commands: pwd, ls, cat, php.',
            parameters: [
                new StringParameter('command', 'Command name', required: true),
                new StringParameter('arguments', 'Optional arguments string', required: false),
            ],
            callback: fn(array $args): ToolResult => ToolResult::success(json_encode(
                $shell->execute((string) $args['command'], (string) ($args['arguments'] ?? '')),
                JSON_UNESCAPED_SLASHES,
            ) ?: '{}'),
        );

        return new StaticToolkit(
            [$tool],
            sprintf('Use run_shell_command to inspect only the sandbox at %s. Do not fabricate command output.', $sandboxPath),
        );
    }

    private function prepareSandbox(string $sessionId): void
    {
        $sandboxPath = $this->config->sandboxPath() . '/' . $sessionId;
        if (!is_dir($sandboxPath)) {
            mkdir($sandboxPath, 0755, true);
            return;
        }

        foreach (new \FilesystemIterator($sandboxPath, \FilesystemIterator::CURRENT_AS_FILEINFO) as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            if ($item->isFile()) {
                unlink($item->getPathname());
            }
        }
    }

    /**
     * @param \Closure(string, array<string, mixed>): void $stream
     */
    /**
     * @phpstan-impure
     * @param \Closure(string, array<string, mixed>): void $stream
     */
    private function waitForRunnableSession(string $sessionId, \Closure $stream, bool $allowPause = true): bool
    {
        $pauseEmitted = false;

        while (true) {
            $status = $this->repository->sessionStatus($sessionId);

            if ($status === null) {
                throw new \RuntimeException('Session not found.');
            }

            if ($status === 'stopped') {
                $this->emit($stream, $sessionId, null, 'session_stopped', ['message' => 'Session stopped by user.']);

                return false;
            }

            if ($status === 'failed') {
                return false;
            }

            if ($allowPause && $status === 'paused') {
                if (!$pauseEmitted) {
                    $this->emit($stream, $sessionId, null, 'session_paused', ['message' => 'Session paused. Waiting for resume.']);
                    $pauseEmitted = true;
                }

                ($this->pauseWaiter)($this->config->sessionControlPollMilliseconds() * 1000);
                continue;
            }

            if ($pauseEmitted && $status === 'running') {
                $this->emit($stream, $sessionId, null, 'session_resumed', ['message' => 'Session resumed.']);
            }

            return true;
        }
    }

    /**
     * @param \Closure(string, array<string, mixed>): void $stream
     */
    private function emit(\Closure $stream, string $sessionId, ?string $attemptId, string $eventType, array $payload): void
    {
        $envelope = [
            'session_id' => $sessionId,
            'attempt_id' => $attemptId,
            'event_type' => $eventType,
            'payload' => $payload,
            'created_at' => gmdate('c'),
        ];

        $this->repository->appendEvent($sessionId, $attemptId, $eventType, $payload);
        $stream($eventType, $envelope);
    }

    /**
     * @param array<string, mixed> $session
     */
    private function seedConfigurationFromSession(array $session): SessionSeedConfiguration
    {
        $type = is_string($session['seed_type'] ?? null)
            ? SeedType::tryFrom((string) $session['seed_type'])
            : null;
        $frequency = is_string($session['seed_frequency'] ?? null)
            ? SeedFrequency::tryFrom((string) $session['seed_frequency'])
            : null;

        return new SessionSeedConfiguration(
            seed: $session['seed'] !== null ? (int) $session['seed'] : null,
            type: $type ?? $this->config->defaultSeedType(),
            frequency: $frequency ?? $this->config->defaultSeedFrequency(),
        );
    }
}