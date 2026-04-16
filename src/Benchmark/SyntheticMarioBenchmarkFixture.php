<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPLLMBenchy\Benchmark;

final class SyntheticMarioBenchmarkFixture
{
    public const string ID = 'mario_speedrun_synthetic';

    /**
     * @return array<string, mixed>
     */
    public static function scenario(): array
    {
        return [
            'course_name' => 'Synthetic World 1-1',
            'goal_x' => 280,
            'max_frames' => 480,
            'target_frames' => 260,
            'max_iterations' => 14,
            'allowed_buttons' => ['RIGHT', 'LEFT', 'B', 'A', 'START'],
            'initial_state' => [
                'playerX' => 0,
                'playerY' => 0,
                'speedX' => 0,
                'speedY' => 0,
                'playerState' => 0,
                'powerup' => 0,
                'lives' => 3,
                'coins' => 0,
                'score' => 0,
                'timer' => 400,
                'levelNumber' => 1,
                'subLevelNumber' => 1,
                'gameMode' => 20,
                'frameCount' => 0,
                'onGround' => true,
                'isDying' => false,
                'levelComplete' => false,
            ],
            'checkpoints' => [
                ['id' => 'opening_runway', 'label' => 'Opening runway', 'x' => 80],
                ['id' => 'goomba_clear', 'label' => 'Goomba cleared', 'x' => 120],
                ['id' => 'pipe_clear', 'label' => 'Pipe cleared', 'x' => 180],
                ['id' => 'koopa_clear', 'label' => 'Koopa cleared', 'x' => 240],
                ['id' => 'goal_tape', 'label' => 'Goal reached', 'x' => 280],
            ],
            'hazards' => [
                ['id' => 'goomba', 'label' => 'Goomba', 'start_x' => 62, 'end_x' => 74, 'required_y' => 3],
                ['id' => 'pipe', 'label' => 'Pipe', 'start_x' => 138, 'end_x' => 154, 'required_y' => 6],
                ['id' => 'koopa', 'label' => 'Koopa', 'start_x' => 214, 'end_x' => 228, 'required_y' => 4],
            ],
            'expected_tools' => ['read_game_state', 'press_buttons', 'wait_frames'],
        ];
    }

    public static function prompt(): string
    {
        return <<<'PROMPT'
Use the synthetic Mario tools to guide Mario through a deterministic World 1-1 style course as fast as possible.

Course notes:
- Start at X=0 with Mario standing still.
- A Goomba blocks the lane around X=62-74.
- A pipe blocks the lane around X=138-154.
- A Koopa blocks the lane around X=214-228.
- The goal is reached at X=280.
- RIGHT builds horizontal speed.
- B and A are jump buttons; momentum carries through jumps, so you can run first and then jump.
- WAIT advances time without adding input.

Read the game state, issue button sequences, and finish the course in the fewest synthetic frames possible.
When you are done, answer with whether the run completed and the total synthetic frame count.
PROMPT;
    }
}