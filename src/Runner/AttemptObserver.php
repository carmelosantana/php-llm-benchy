<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPLLMBenchy\Runner;

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use SplObserver;
use SplSubject;

final class AttemptObserver implements SplObserver
{
    private string $text = '';

    private string $reasoning = '';

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $toolCalls = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $toolResults = [];

    public function __construct(
        private readonly \Closure $emit,
    ) {}

    public function update(SplSubject $subject): void
    {
        if (!$subject instanceof AbstractAgent) {
            return;
        }

        $event = $subject->lastEvent();
        $data = $subject->lastEventData();

        match ($event) {
            'agent.iteration' => ($this->emit)('iteration', ['number' => (int) $data]),
            'agent.reasoning' => $this->handleReasoning((string) $data),
            'agent.text_delta' => $this->handleTextDelta((string) $data),
            'agent.tool_call' => $this->handleToolCall($data),
            'agent.tool_result' => $this->handleToolResult($data),
            'agent.error' => ($this->emit)('error', ['message' => (string) $data]),
            'agent.done' => ($this->emit)('done', ['message' => 'Attempt completed']),
            default => null,
        };
    }

    public function text(): string
    {
        return $this->text;
    }

    public function reasoning(): string
    {
        return $this->reasoning;
    }

    public function metrics(): array
    {
        return [
            'tool_calls' => $this->toolCalls,
            'tool_results' => $this->toolResults,
            'tool_names' => array_values(array_unique(array_map(
                static fn(array $call): string => (string) $call['name'],
                $this->toolCalls,
            ))),
        ];
    }

    private function handleReasoning(string $delta): void
    {
        if ($delta === '') {
            return;
        }

        $this->reasoning .= $delta;
        ($this->emit)('reasoning_delta', ['delta' => $delta]);
    }

    private function handleTextDelta(string $delta): void
    {
        if ($delta === '') {
            return;
        }

        $this->text .= $delta;
        ($this->emit)('text_delta', ['delta' => $delta]);
    }

    private function handleToolCall(mixed $data): void
    {
        if (!$data instanceof ToolCall) {
            return;
        }

        $payload = [
            'id' => $data->id,
            'name' => $data->name,
            'arguments' => $data->arguments,
        ];
        $this->toolCalls[] = $payload;
        ($this->emit)('tool_call', $payload);
    }

    private function handleToolResult(mixed $data): void
    {
        if (!$data instanceof ToolResult) {
            return;
        }

        $payload = [
            'status' => $data->status->value,
            'content' => $data->content,
            'call_id' => $data->callId,
        ];
        $this->toolResults[] = $payload;
        ($this->emit)('tool_result', [
            'status' => $payload['status'],
            'call_id' => $payload['call_id'],
            'preview' => mb_substr($payload['content'], 0, 280),
        ]);
    }
}