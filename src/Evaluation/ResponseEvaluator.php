<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPLLMBenchy\Evaluation;

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPLLMBenchy\Benchmark\BenchmarkDefinition;
use CarmeloSantana\PHPLLMBenchy\Benchmark\SyntheticMarioBenchmarkFixture;
use CarmeloSantana\PHPLLMBenchy\Config\AppConfig;
use Symfony\Component\Process\Process;

final readonly class ResponseEvaluator
{
    public function __construct(
        private ProviderInterface $provider,
        private AppConfig $config,
    ) {}

    public function scoreCapability(BenchmarkDefinition $benchmark, string $responseText, array $metrics): array
    {
        return match ($benchmark->id) {
            'tool_use' => $this->scoreToolUse($benchmark, $responseText, $metrics),
            'concurrent_tool_use' => $this->scoreConcurrentToolUse($benchmark, $responseText, $metrics),
            SyntheticMarioBenchmarkFixture::ID => $this->scoreSyntheticMario($benchmark, $responseText, $metrics),
            'memory_recall' => $this->scoreMemoryRecall($benchmark, $responseText),
            'shell_execution' => $this->scoreShellExecution($benchmark, $responseText, $metrics),
            'php_script' => $this->scorePhpScript($benchmark, $responseText),
            'creative_story' => $this->scoreCreativeStory($benchmark, $responseText),
            'poem' => $this->scorePoem($benchmark, $responseText),
            default => ['score' => 0.0, 'checks' => []],
        };
    }

    public function scoreQuality(BenchmarkDefinition $benchmark, string $responseText): array
    {
        if (trim($responseText) === '') {
            return [
                'relevance' => 0,
                'coherence' => 0,
                'creativity' => 0,
                'accuracy' => 0,
                'fluency' => 0,
                'notes' => 'No response text was produced.',
                'total' => 0,
            ];
        }

        $system = new SystemMessage(<<<'PROMPT'
You are a strict benchmark judge. Score the model response on exactly five criteria from 0 to 10: relevance, coherence, creativity, accuracy, fluency.
Return only a JSON object with integer scores and a short notes string.
PROMPT);

        $user = new UserMessage(sprintf(
            "Benchmark: %s\nDescription: %s\nPrompt:\n%s\n\nResponse:\n%s\n\nReturn JSON like {\"relevance\":0,\"coherence\":0,\"creativity\":0,\"accuracy\":0,\"fluency\":0,\"notes\":\"...\"}",
            $benchmark->name,
            $benchmark->description,
            $benchmark->prompt,
            $responseText,
        ));

        try {
            $response = $this->provider->chat([$system, $user], [], ['temperature' => 0.1]);
            $rubric = $this->extractJson((string) $response->content);
        } catch (\Throwable $e) {
            $rubric = null;
        }

        if (!is_array($rubric)) {
            return [
                'relevance' => 0,
                'coherence' => 0,
                'creativity' => 0,
                'accuracy' => 0,
                'fluency' => 0,
                'notes' => 'The evaluation model did not return parseable rubric JSON.',
                'total' => 0,
            ];
        }

        $normalized = [];
        foreach (['relevance', 'coherence', 'creativity', 'accuracy', 'fluency'] as $criterion) {
            $normalized[$criterion] = max(0, min(10, (int) ($rubric[$criterion] ?? 0)));
        }
        $normalized['notes'] = (string) ($rubric['notes'] ?? '');
        $normalized['total'] = array_sum(array_intersect_key($normalized, array_flip(['relevance', 'coherence', 'creativity', 'accuracy', 'fluency'])));

        return $normalized;
    }

    private function scoreToolUse(BenchmarkDefinition $benchmark, string $responseText, array $metrics): array
    {
        $checks = [];
        $score = 0.0;
        $scenario = $benchmark->scenario;
        $toolCalls = $metrics['tool_calls'] ?? [];
        $toolNames = $metrics['tool_names'] ?? [];

        $usedRequiredTool = in_array('add_numbers', $toolNames, true);
        $checks['used_required_tool'] = $usedRequiredTool;
        if ($usedRequiredTool) {
            $score += 20;
        }

        $argsCorrect = false;
        foreach ($toolCalls as $call) {
            if (($call['name'] ?? '') !== 'add_numbers') {
                continue;
            }
            $normalized = preg_replace('/\s+/', '', strtolower((string) ($call['arguments']['numbers'] ?? '')));
            if ($normalized === '17,25,8') {
                $argsCorrect = true;
                break;
            }
        }
        $checks['arguments_correct'] = $argsCorrect;
        if ($argsCorrect) {
            $score += 15;
        }

        $completed = str_contains($responseText, (string) $scenario['expected_total']);
        $checks['completed_with_correct_total'] = $completed;
        if ($completed) {
            $score += 15;
        }

        return ['score' => $score, 'checks' => $checks];
    }

    private function scoreConcurrentToolUse(BenchmarkDefinition $benchmark, string $responseText, array $metrics): array
    {
        $checks = [];
        $score = 0.0;
        $toolCalls = $metrics['tool_calls'] ?? [];
        $toolNames = array_values(array_unique($metrics['tool_names'] ?? []));
        $requiredTools = $benchmark->scenario['required_tools'] ?? [];

        $coverage = count(array_intersect($requiredTools, $toolNames));
        $checks['required_tools_called'] = $coverage;
        if ($coverage === count($requiredTools)) {
            $score += 20;
        } elseif ($coverage > 0) {
            $score += round(20 * ($coverage / max(1, count($requiredTools))), 2);
        }

        $cityCorrect = 0;
        foreach ($toolCalls as $call) {
            if (in_array($call['name'] ?? '', $requiredTools, true) && strtolower((string) ($call['arguments']['city'] ?? '')) === 'lisbon') {
                $cityCorrect++;
            }
        }
        $checks['city_arguments_correct'] = $cityCorrect;
        if ($cityCorrect === count($requiredTools)) {
            $score += 15;
        } elseif ($cityCorrect > 0) {
            $score += round(15 * ($cityCorrect / max(1, count($requiredTools))), 2);
        }

        $expectedFragments = $benchmark->scenario['expected_fragments'] ?? [];
        $found = 0;
        foreach ($expectedFragments as $fragment) {
            if (stripos($responseText, (string) $fragment) !== false) {
                $found++;
            }
        }
        $checks['synthesis_fragments_found'] = $found;
        if ($found === count($expectedFragments)) {
            $score += 15;
        } elseif ($found > 0) {
            $score += round(15 * ($found / max(1, count($expectedFragments))), 2);
        }

        return ['score' => $score, 'checks' => $checks];
    }

    private function scoreMemoryRecall(BenchmarkDefinition $benchmark, string $responseText): array
    {
        $checks = [];
        $score = 0.0;
        $expectedFragments = $benchmark->scenario['expected_fragments'] ?? [];
        $forbiddenFragments = $benchmark->scenario['forbidden_fragments'] ?? [];

        $found = 0;
        foreach ($expectedFragments as $fragment) {
            if (stripos($responseText, (string) $fragment) !== false) {
                $found++;
            }
        }
        $checks['facts_found'] = $found;
        $score += round(30 * ($found / max(1, count($expectedFragments))), 2);

        $contradictions = 0;
        foreach ($forbiddenFragments as $fragment) {
            if (stripos($responseText, (string) $fragment) !== false) {
                $contradictions++;
            }
        }
        $checks['forbidden_fragments_found'] = $contradictions;
        if ($contradictions === 0) {
            $score += 10;
        }

        $wordCount = $this->wordCount($responseText);
        $checks['word_count'] = $wordCount;
        if ($wordCount <= 80) {
            $score += 10;
        }

        return ['score' => $score, 'checks' => $checks];
    }

    private function scoreSyntheticMario(BenchmarkDefinition $benchmark, string $responseText, array $metrics): array
    {
        $checks = [];
        $score = 0.0;
        $summary = is_array($metrics['synthetic_mario'] ?? null) ? $metrics['synthetic_mario'] : [];
        $checkpointCount = (int) ($summary['checkpoint_count'] ?? count($benchmark->scenario['checkpoints'] ?? []));
        $checkpointsCleared = (int) ($summary['checkpoints_cleared'] ?? 0);
        $completed = ($summary['completed'] ?? false) === true;

        $checks['completed'] = $completed;
        $checks['checkpoints_cleared'] = $checkpointsCleared;
        $checks['checkpoint_count'] = $checkpointCount;

        if ($checkpointCount > 0) {
            $score += round(15 * ($checkpointsCleared / $checkpointCount), 2);
        }

        if ($completed) {
            $score += 10;
        }

        $framesUsed = (int) ($summary['frames_used'] ?? 0);
        $targetFrames = (int) ($summary['target_frames'] ?? ($benchmark->scenario['target_frames'] ?? 0));
        $maxFrames = (int) ($summary['max_frames'] ?? ($benchmark->scenario['max_frames'] ?? 0));
        $checks['frames_used'] = $framesUsed;

        if ($completed && $maxFrames > $targetFrames && $framesUsed > 0) {
            $normalized = ($maxFrames - $framesUsed) / ($maxFrames - $targetFrames);
            $score += round(15 * max(0.0, min(1.0, $normalized)), 2);
        }

        $toolNames = array_values(array_unique($metrics['tool_names'] ?? []));
        $requiredTools = $benchmark->scenario['expected_tools'] ?? [];
        $coverage = count(array_intersect($requiredTools, $toolNames));
        $reads = (int) ($summary['reads'] ?? 0);
        $actions = (int) ($summary['actions'] ?? 0);
        $checks['required_tools_called'] = $coverage;
        $checks['reads'] = $reads;
        $checks['actions'] = $actions;

        if ($reads >= 1 && $actions >= 1) {
            $toolUseScore = 2.5;
            if ($reads >= 2) {
                $toolUseScore += 1.5;
            }
            if ($coverage === count($requiredTools) && count($requiredTools) > 0) {
                $toolUseScore += 1.0;
            }
            $score += min(5.0, $toolUseScore);
        }

        $invalidActions = (int) ($summary['invalid_actions'] ?? 0);
        $checks['invalid_actions'] = $invalidActions;
        $checks['deaths'] = (int) ($summary['deaths'] ?? 0);
        $checks['failure_reason'] = (string) ($summary['failure_reason'] ?? '');

        if ($invalidActions === 0 && $actions > 0) {
            $score += 5;
        } elseif ($invalidActions === 1 && $actions > 0) {
            $score += 2.5;
        }

        return ['score' => min(50.0, round($score, 2)), 'checks' => $checks];
    }

    private function scoreShellExecution(BenchmarkDefinition $benchmark, string $responseText, array $metrics): array
    {
        $checks = [];
        $score = 0.0;
        $toolCalls = $metrics['tool_calls'] ?? [];
        $toolResults = $metrics['tool_results'] ?? [];

        $safe = count($toolCalls) > 0;
        foreach ($toolCalls as $call) {
            $safe = $safe && (($call['name'] ?? '') === 'run_shell_command');
        }
        $checks['safe_tool_usage'] = $safe;
        if ($safe) {
            $score += 10;
        }

        $successfulCommands = 0;
        foreach ($toolResults as $result) {
            if (($result['status'] ?? '') === 'success') {
                $decoded = $this->extractJson((string) ($result['content'] ?? ''));
                if (is_array($decoded) && (($decoded['success'] ?? false) === true)) {
                    $successfulCommands++;
                }
            }
        }
        $checks['successful_commands'] = $successfulCommands;
        if ($successfulCommands >= 2) {
            $score += 20;
        } elseif ($successfulCommands === 1) {
            $score += 10;
        }

        $containsSecret = false;
        foreach ($benchmark->scenario['expected_fragments'] ?? [] as $fragment) {
            if (stripos($responseText, (string) $fragment) !== false) {
                $containsSecret = true;
                break;
            }
        }
        $checks['expected_output_found'] = $containsSecret;
        if ($containsSecret) {
            $score += 20;
        }

        return ['score' => $score, 'checks' => $checks];
    }

    private function scorePhpScript(BenchmarkDefinition $benchmark, string $responseText): array
    {
        $checks = [];
        $score = 0.0;
        $code = $this->extractPhpCode($responseText);
        $checks['extracted_code_length'] = strlen($code);

        if ($code !== '' && !$this->containsMarkdownFence($responseText)) {
            $score += 10;
            $checks['instruction_compliance'] = true;
        } else {
            $checks['instruction_compliance'] = false;
        }

        if ($code === '') {
            return ['score' => $score, 'checks' => $checks];
        }

        $sandbox = $this->config->sandboxPath() . '/php-eval';
        if (!is_dir($sandbox)) {
            mkdir($sandbox, 0755, true);
        }
        $file = $sandbox . '/attempt.php';
        file_put_contents($file, $code);

        $lint = new Process(['php', '-l', $file]);
        $lint->setTimeout(10);
        $lint->run();
        $checks['lint_success'] = $lint->isSuccessful();
        if ($lint->isSuccessful()) {
            $score += 20;
        }

        $functionName = (string) ($benchmark->scenario['function_name'] ?? 'benchy_fizzbuzz');
        $input = (int) ($benchmark->scenario['test_input'] ?? 15);
        $runner = new Process([
            'php',
            '-ddisplay_errors=0',
            '-r',
            sprintf('require %s; echo json_encode(%s(%d));', var_export($file, true), $functionName, $input),
        ]);
        $runner->setTimeout(10);
        $runner->run();
        $decoded = $this->decodeRuntimeJsonArray($runner->getOutput());
        $expected = $benchmark->scenario['expected_output'] ?? [];
        $checks['behavior_matches_expected'] = $decoded === $expected;
        if ($decoded === $expected) {
            $score += 20;
        }

        return ['score' => $score, 'checks' => $checks];
    }

    private function scoreCreativeStory(BenchmarkDefinition $benchmark, string $responseText): array
    {
        $checks = [];
        $score = 0.0;
        $wordCount = $this->wordCount($responseText);
        $requiredPhrase = (string) ($benchmark->scenario['required_phrase'] ?? '');
        $requiredTerms = $benchmark->scenario['required_terms'] ?? [];

        $checks['contains_required_phrase'] = str_contains($responseText, $requiredPhrase);
        $checks['word_count'] = $wordCount;
        $checks['required_terms_found'] = $this->countFragments($responseText, $requiredTerms);

        if ($checks['contains_required_phrase']) {
            $score += 10;
        }
        if ($checks['required_terms_found'] === count($requiredTerms)) {
            $score += 10;
        }
        if ($wordCount >= (int) $benchmark->scenario['min_words'] && $wordCount <= (int) $benchmark->scenario['max_words']) {
            $score += 15;
        }
        if (substr_count(trim($responseText), "\n") >= 2) {
            $score += 15;
        }

        return ['score' => $score, 'checks' => $checks];
    }

    private function scorePoem(BenchmarkDefinition $benchmark, string $responseText): array
    {
        $checks = [];
        $score = 0.0;
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', trim($responseText)) ?: []), static fn(string $line): bool => $line !== ''));
        $checks['line_count'] = count($lines);
        $checks['has_required_line'] = in_array((string) $benchmark->scenario['required_line'], $lines, true);
        $checks['required_terms_found'] = $this->countFragments($responseText, $benchmark->scenario['required_terms'] ?? []);

        if ($checks['required_terms_found'] === count($benchmark->scenario['required_terms'] ?? [])) {
            $score += 20;
        }
        if ($checks['line_count'] === (int) ($benchmark->scenario['line_count'] ?? 12)) {
            $score += 15;
        }
        if ($checks['has_required_line']) {
            $score += 15;
        }

        return ['score' => $score, 'checks' => $checks];
    }

    private function extractJson(string $content): ?array
    {
        $content = trim($content);
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/```(?:json)?\s*(\{[\s\S]*\})\s*```/i', $content, $matches) === 1) {
            $decoded = json_decode($matches[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if (preg_match('/(\{[\s\S]*\})/', $content, $matches) === 1) {
            $decoded = json_decode($matches[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function extractPhpCode(string $responseText): string
    {
        if (preg_match('/```php\s*([\s\S]*?)```/i', $responseText, $matches) === 1) {
            return trim($matches[1]);
        }

        if (preg_match('/```\s*([\s\S]*?)```/i', $responseText, $matches) === 1) {
            return trim($matches[1]);
        }

        return trim($responseText);
    }

    private function containsMarkdownFence(string $responseText): bool
    {
        return str_contains($responseText, '```');
    }

    private function wordCount(string $text): int
    {
        return str_word_count(strip_tags($text));
    }

    private function countFragments(string $text, array $fragments): int
    {
        $found = 0;
        foreach ($fragments as $fragment) {
            if (stripos($text, (string) $fragment) !== false) {
                $found++;
            }
        }

        return $found;
    }

    /**
     * @return array<mixed>|null
     */
    private function decodeRuntimeJsonArray(string $output): ?array
    {
        $decoded = json_decode(trim($output), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $lines = preg_split('/\R/', trim($output)) ?: [];
        for ($index = count($lines) - 1; $index >= 0; $index--) {
            $candidate = trim($lines[$index]);
            if ($candidate === '') {
                continue;
            }

            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}