<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPLLMBenchy\Benchmark;

final class BenchmarkRegistry
{
    /**
     * @return array<string, BenchmarkDefinition>
     */
    public function all(): array
    {
        return [
            'tool_use' => new BenchmarkDefinition(
                id: 'tool_use',
                name: 'Tool Use',
                description: 'Tests whether the model selects the correct tool, supplies valid arguments, and completes the task successfully.',
                prompt: 'Use the available tools to compute 17 + 25 + 8, then answer with the final total and a short explanation of how you got it.',
                capabilityWeights: [
                    'correct_tool_choice' => 20,
                    'argument_correctness' => 15,
                    'successful_completion' => 15,
                ],
                scenario: [
                    'numbers' => [17, 25, 8],
                    'expected_total' => 50,
                    'required_tools' => ['add_numbers'],
                ],
                tags: ['tools', 'math'],
            ),
            'concurrent_tool_use' => new BenchmarkDefinition(
                id: 'concurrent_tool_use',
                name: 'Concurrent Tool Use',
                description: 'Tests whether the model can call multiple independent tools in one task and synthesize the results coherently.',
                prompt: 'Use the tools to gather the population, timezone, and weather for Lisbon, then provide a concise briefing that includes all three facts.',
                capabilityWeights: [
                    'required_multi_tool_coverage' => 20,
                    'argument_correctness' => 15,
                    'final_synthesis' => 15,
                ],
                scenario: [
                    'city' => 'Lisbon',
                    'required_tools' => ['lookup_population', 'lookup_timezone', 'lookup_weather'],
                    'expected_fragments' => ['504718', 'Europe/Lisbon', 'Sunny 22C'],
                ],
                tags: ['tools', 'multi-tool'],
            ),
            'memory_recall' => new BenchmarkDefinition(
                id: 'memory_recall',
                name: 'Memory Recall',
                description: 'Tests whether the model correctly recalls facts from a prior mock conversation without contradicting them.',
                prompt: 'Based on our previous conversation only, tell me the user\'s favorite fruit, their dog\'s name, and what shift they work. Do not invent anything else.',
                capabilityWeights: [
                    'required_fact_recall' => 30,
                    'contradiction_avoidance' => 10,
                    'instruction_compliance' => 10,
                ],
                scenario: [
                    'history' => [
                        ['role' => 'user', 'content' => 'I switched to the night shift last month and it has been intense.'],
                        ['role' => 'assistant', 'content' => 'I\'ll remember that you work nights now.'],
                        ['role' => 'user', 'content' => 'My favorite fruit is kiwi, and my dog Jade still hates thunderstorms.'],
                    ],
                    'expected_fragments' => ['kiwi', 'Jade', 'night shift'],
                    'forbidden_fragments' => ['day shift', 'apple', 'Milo'],
                ],
                tags: ['memory', 'conversation'],
            ),
            'shell_execution' => new BenchmarkDefinition(
                id: 'shell_execution',
                name: 'Shell Execution',
                description: 'Tests whether the model safely uses a restricted shell tool to inspect a sandbox and report the requested result.',
                prompt: 'Use the shell tool to inspect the sandbox, read the note file, and tell me the secret code exactly as written.',
                capabilityWeights: [
                    'safe_command_selection' => 10,
                    'correct_command_execution' => 20,
                    'expected_output_or_artifact' => 20,
                ],
                scenario: [
                    'note_file' => 'notes.txt',
                    'note_contents' => "Secret code: BX-204\nReminder: do not guess; inspect the file.\n",
                    'expected_fragments' => ['BX-204'],
                ],
                tags: ['shell', 'sandbox'],
            ),
            'php_script' => new BenchmarkDefinition(
                id: 'php_script',
                name: 'PHP Script',
                description: 'Tests whether the model can write valid PHP that behaves correctly for a concrete programming task.',
                prompt: 'Write only PHP code. Define a function named benchy_fizzbuzz(int $n): array that returns a FizzBuzz sequence from 1 through $n. Return plain PHP with no markdown fences and no explanation.',
                capabilityWeights: [
                    'valid_php_output' => 20,
                    'expected_behavior_or_test_pass' => 20,
                    'instruction_compliance' => 10,
                ],
                scenario: [
                    'function_name' => 'benchy_fizzbuzz',
                    'test_input' => 15,
                    'expected_output' => ['1', '2', 'Fizz', '4', 'Buzz', 'Fizz', '7', '8', 'Fizz', 'Buzz', '11', 'Fizz', '13', '14', 'FizzBuzz'],
                ],
                tags: ['code', 'php'],
            ),
            'creative_story' => new BenchmarkDefinition(
                id: 'creative_story',
                name: 'Creative Story',
                description: 'Tests prompt adherence and creative writing quality for a short narrative task.',
                prompt: 'Write a short story between 350 and 500 words about a lighthouse keeper who discovers a signal hidden in the fog. Include the exact phrase "the sea remembered first" somewhere in the story.',
                capabilityWeights: [
                    'prompt_adherence' => 20,
                    'narrative_completeness' => 15,
                    'constraint_satisfaction' => 15,
                ],
                scenario: [
                    'required_phrase' => 'the sea remembered first',
                    'min_words' => 350,
                    'max_words' => 500,
                    'required_terms' => ['lighthouse', 'fog'],
                ],
                tags: ['creative', 'story'],
            ),
            'poem' => new BenchmarkDefinition(
                id: 'poem',
                name: 'Poem',
                description: 'Tests poetic structure and constraint following on a compact creative task.',
                prompt: 'Write a 12-line poem about midnight trains. Include the exact line "Steel dreams hum below the moon." somewhere in the poem.',
                capabilityWeights: [
                    'prompt_adherence' => 20,
                    'poetic_structure_compliance' => 15,
                    'constraint_satisfaction' => 15,
                ],
                scenario: [
                    'required_line' => 'Steel dreams hum below the moon.',
                    'line_count' => 12,
                    'required_terms' => ['train', 'midnight'],
                ],
                tags: ['creative', 'poetry'],
            ),
        ];
    }

    public function find(string $id): ?BenchmarkDefinition
    {
        $definitions = $this->all();

        return $definitions[$id] ?? null;
    }

    public function catalog(): array
    {
        return array_map(
            static fn(BenchmarkDefinition $definition): array => $definition->toArray(),
            array_values($this->all()),
        );
    }
}