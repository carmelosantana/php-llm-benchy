<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPLLMBenchy\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\ArrayParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\ObjectParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPLLMBenchy\Benchmark\SyntheticMarioBenchmarkFixture;
use CarmeloSantana\PHPLLMBenchy\Benchmark\SyntheticMarioSimulator;

final class SyntheticMarioToolkit implements ToolkitInterface, BenchmarkTelemetryAwareToolkit
{
    private SyntheticMarioSimulator $simulator;

    /**
     * @param array<string, mixed>|null $scenario
     */
    public function __construct(?array $scenario = null)
    {
        $this->simulator = new SyntheticMarioSimulator($scenario ?? SyntheticMarioBenchmarkFixture::scenario());
    }

    public function tools(): array
    {
        return [
            $this->readGameStateTool(),
            $this->pressButtonsTool(),
            $this->waitFramesTool(),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
        ## Synthetic Mario Controller
        Available buttons: RIGHT, LEFT, B, A, START

        ## Course Rules
        - RIGHT builds and maintains forward speed.
        - B and A trigger jumps; horizontal momentum carries through the jump.
        - WAIT advances time without new input.
        - Read the game state before and after major actions.
        - The synthetic course has a Goomba near X=62-74, a pipe near X=138-154, a Koopa near X=214-228, and a goal at X=280.
        - Invalid button names or oversized frame counts are penalized.
        GUIDELINES;
    }

    public function benchmarkMetrics(): array
    {
        return [
            'synthetic_mario' => $this->simulator->summary(),
        ];
    }

    private function readGameStateTool(): Tool
    {
        return new Tool(
            name: 'read_game_state',
            description: 'Read the current synthetic Mario game state: position, speed, timer, lives, and completion flags.',
            parameters: [],
            callback: fn(array $args): ToolResult => ToolResult::success($this->simulator->readStateJson()),
        );
    }

    private function pressButtonsTool(): Tool
    {
        return new Tool(
            name: 'press_buttons',
            description: 'Execute a sequence of button presses. Each step has a single button and a frame count.',
            parameters: [
                new ArrayParameter(
                    name: 'sequence',
                    description: 'Ordered array of button steps',
                    items: new ObjectParameter(
                        name: 'step',
                        description: 'A single button press step',
                        properties: [
                            new StringParameter('button', 'Button name: RIGHT, LEFT, B, A, START', required: true),
                            new NumberParameter('frames', 'Number of frames to hold the button', required: true),
                        ],
                    ),
                    required: true,
                ),
            ],
            callback: function (array $args): ToolResult {
                $sequence = $args['sequence'] ?? [];

                if (is_string($sequence)) {
                    $decoded = json_decode($sequence, true);
                    if (is_array($decoded)) {
                        $sequence = $decoded;
                    }
                }

                if (!is_array($sequence) || $sequence === []) {
                    return ToolResult::error('No button sequence provided');
                }

                $steps = [];
                foreach ($sequence as $step) {
                    $steps[] = [
                        'button' => strtoupper((string) ($step['button'] ?? '')),
                        'frames' => (int) ($step['frames'] ?? 0),
                    ];
                }

                return ToolResult::success(json_encode($this->simulator->pressButtons($steps), JSON_UNESCAPED_SLASHES) ?: '{}');
            },
        );
    }

    private function waitFramesTool(): Tool
    {
        return new Tool(
            name: 'wait_frames',
            description: 'Advance the synthetic course by N frames without new control input.',
            parameters: [
                new NumberParameter('count', 'Frames to wait', required: true),
            ],
            callback: fn(array $args): ToolResult => ToolResult::success(json_encode(
                $this->simulator->waitFrames((int) ($args['count'] ?? 0)),
                JSON_UNESCAPED_SLASHES,
            ) ?: '{}'),
        );
    }
}