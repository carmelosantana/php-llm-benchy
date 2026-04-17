<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPLLMBenchy\Benchmark;

final class SyntheticMarioSimulator
{
    /** @var array<string, mixed> */
    private array $scenario;

    /** @var array<string, mixed> */
    private array $state;

    /** @var array<string, bool> */
    private array $checkpointMap = [];

    /** @var array<string, bool> */
    private array $hazardMap = [];

    /** @var list<array{button: string, frames: int}> */
    private array $actionLog = [];

    private int $reads = 0;
    private int $actions = 0;
    private int $waits = 0;
    private int $invalidActions = 0;
    private int $deaths = 0;
    private bool $completed = false;
    private bool $failed = false;
    private ?string $failureReason = null;

    /**
     * @param array<string, mixed>|null $scenario
     */
    public function __construct(?array $scenario = null)
    {
        $this->scenario = $scenario ?? SyntheticMarioBenchmarkFixture::scenario();
        $this->state = $this->scenario['initial_state'];
    }

    /**
     * @return array<string, mixed>
     */
    public function readState(): array
    {
        $this->reads++;

        return $this->normalizedState();
    }

    public function readStateJson(): string
    {
        return json_encode($this->readState(), JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<array{button: string, frames: int}> $steps
     * @return array<string, mixed>
     */
    public function pressButtons(array $steps): array
    {
        if ($this->completed || $this->failed) {
            return $this->summary();
        }

        $this->actions++;

        foreach ($steps as $step) {
            $button = strtoupper($step['button']);
            $frames = $step['frames'];

            if (!$this->isValidButton($button) || $frames < 1 || $frames > 240) {
                $this->invalidActions++;
                $this->failureReason ??= 'invalid_control_sequence';

                continue;
            }

            $this->actionLog[] = ['button' => $button, 'frames' => $frames];

            for ($frame = 0; $frame < $frames; $frame++) {
                $this->advanceFrame($button);

                if ($this->completed || $this->failed) {
                    break 2;
                }
            }
        }

        return $this->summary();
    }

    /**
     * @return array<string, mixed>
     */
    public function waitFrames(int $count): array
    {
        if ($count < 1 || $count > 180) {
            $this->invalidActions++;
            $this->failureReason ??= 'invalid_wait';

            return $this->summary();
        }

        if ($this->completed || $this->failed) {
            return $this->summary();
        }

        $this->waits++;

        for ($frame = 0; $frame < $count; $frame++) {
            $this->advanceFrame('WAIT');

            if ($this->completed || $this->failed) {
                break;
            }
        }

        return $this->summary();
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $checkpointCount = count($this->scenario['checkpoints']);
        $checkpointsCleared = count($this->checkpointMap);

        return [
            'completed' => $this->completed,
            'failed' => $this->failed,
            'failure_reason' => $this->failureReason,
            'frames_used' => (int) $this->state['frameCount'],
            'max_frames' => (int) $this->scenario['max_frames'],
            'target_frames' => (int) $this->scenario['target_frames'],
            'checkpoints_cleared' => $checkpointsCleared,
            'checkpoint_count' => $checkpointCount,
            'checkpoint_ids' => array_keys($this->checkpointMap),
            'reads' => $this->reads,
            'actions' => $this->actions,
            'waits' => $this->waits,
            'invalid_actions' => $this->invalidActions,
            'deaths' => $this->deaths,
            'goal_x' => (int) $this->scenario['goal_x'],
            'final_state' => $this->normalizedState(),
            'action_log' => $this->actionLog,
        ];
    }

    private function isValidButton(string $button): bool
    {
        return in_array($button, $this->scenario['allowed_buttons'], true);
    }

    private function advanceFrame(string $button): void
    {
        if ($this->completed || $this->failed) {
            return;
        }

        $speedX = (int) $this->state['speedX'];
        $speedY = (int) $this->state['speedY'];
        $playerY = (int) $this->state['playerY'];
        $onGround = (bool) $this->state['onGround'];

        if ($button === 'RIGHT') {
            $speedX = min(6, $speedX + 1);
        } elseif ($button === 'LEFT') {
            $speedX = max(-3, $speedX - 1);
        } elseif ($speedX > 0) {
            $speedX = max(0, $speedX - 1);
        } elseif ($speedX < 0) {
            $speedX = min(0, $speedX + 1);
        }

        if (($button === 'B' || $button === 'A') && $onGround) {
            $speedY = $button === 'A' ? 7 : 6;
            $onGround = false;
        }

        if (!$onGround) {
            $playerY += $speedY;
            $speedY -= 1;

            if ($playerY <= 0) {
                $playerY = 0;
                $speedY = 0;
                $onGround = true;
            }
        }

        $playerX = max(0, (int) $this->state['playerX'] + $speedX);
        $frameCount = (int) $this->state['frameCount'] + 1;

        $this->state['playerX'] = $playerX;
        $this->state['playerY'] = $playerY;
        $this->state['speedX'] = $speedX;
        $this->state['speedY'] = $speedY;
        $this->state['onGround'] = $onGround;
        $this->state['frameCount'] = $frameCount;
        $this->state['timer'] = max(0, 400 - (int) floor($frameCount / 4));
        $this->state['score'] = max((int) $this->state['score'], $playerX * 10);

        $this->updateCheckpoints();
        $this->checkHazards();

        if ($playerX >= (int) $this->scenario['goal_x']) {
            $this->completed = true;
            $this->state['levelComplete'] = true;
            $this->state['gameMode'] = 21;
        }

        if ($frameCount >= (int) $this->scenario['max_frames'] && !$this->completed) {
            $this->failed = true;
            $this->failureReason ??= 'time_limit_exceeded';
        }
    }

    private function updateCheckpoints(): void
    {
        foreach ($this->scenario['checkpoints'] as $checkpoint) {
            $id = (string) $checkpoint['id'];

            if (isset($this->checkpointMap[$id])) {
                continue;
            }

            if ((int) $this->state['playerX'] >= (int) $checkpoint['x']) {
                $this->checkpointMap[$id] = true;
            }
        }
    }

    private function checkHazards(): void
    {
        foreach ($this->scenario['hazards'] as $hazard) {
            $id = (string) $hazard['id'];

            if (isset($this->hazardMap[$id])) {
                continue;
            }

            $x = (int) $this->state['playerX'];

            if ($x < (int) $hazard['start_x']) {
                continue;
            }

            if ($x <= (int) $hazard['end_x'] && (int) $this->state['playerY'] < (int) $hazard['required_y']) {
                $this->failed = true;
                $this->failureReason = 'collision_with_' . $id;
                $this->deaths++;
                $this->state['lives'] = max(0, (int) $this->state['lives'] - 1);
                $this->state['playerState'] = 9;
                $this->state['gameMode'] = 9;
                $this->state['isDying'] = true;
                $this->state['speedX'] = 0;
                $this->state['speedY'] = 0;

                return;
            }

            if ($x > (int) $hazard['end_x']) {
                $this->hazardMap[$id] = true;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizedState(): array
    {
        return [
            'playerX' => (int) $this->state['playerX'],
            'playerY' => (int) $this->state['playerY'],
            'speedX' => (int) $this->state['speedX'],
            'speedY' => (int) $this->state['speedY'],
            'playerState' => (int) $this->state['playerState'],
            'powerup' => (int) $this->state['powerup'],
            'lives' => (int) $this->state['lives'],
            'coins' => (int) $this->state['coins'],
            'score' => (int) $this->state['score'],
            'timer' => (int) $this->state['timer'],
            'levelNumber' => (int) $this->state['levelNumber'],
            'subLevelNumber' => (int) $this->state['subLevelNumber'],
            'gameMode' => (int) $this->state['gameMode'],
            'frameCount' => (int) $this->state['frameCount'],
            'onGround' => (bool) $this->state['onGround'],
            'isDying' => (bool) $this->state['isDying'],
            'levelComplete' => (bool) $this->state['levelComplete'],
        ];
    }
}