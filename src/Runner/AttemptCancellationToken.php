<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPLLMBenchy\Runner;

use CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface;
use CarmeloSantana\PHPLLMBenchy\Repository\SessionRepository;

final class AttemptCancellationToken implements CancellationTokenInterface
{
    private ?string $reason = null;

    private float $lastStatusCheckAt = 0.0;

    public function __construct(
        private readonly SessionRepository $repository,
        private readonly string $sessionId,
        private readonly ?float $deadlineAt = null,
        private readonly int $statusCheckIntervalMilliseconds = 250,
    ) {}

    public function isCancelled(): bool
    {
        if ($this->reason !== null) {
            return true;
        }

        $now = microtime(true);

        if ($this->deadlineAt !== null && $now >= $this->deadlineAt) {
            $this->reason = 'timeout';

            return true;
        }

        $minimumInterval = max(0.05, $this->statusCheckIntervalMilliseconds / 1000);
        if (($now - $this->lastStatusCheckAt) < $minimumInterval) {
            return false;
        }

        $this->lastStatusCheckAt = $now;
        if ($this->repository->sessionStatus($this->sessionId) === 'stopped') {
            $this->reason = 'stopped';

            return true;
        }

        return false;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }
}