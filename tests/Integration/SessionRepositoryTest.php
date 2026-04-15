<?php

declare(strict_types=1);

use CarmeloSantana\PHPLLMBenchy\Config\AppConfig;
use CarmeloSantana\PHPLLMBenchy\Repository\SessionRepository;
use CarmeloSantana\PHPLLMBenchy\Storage\Database;

it('persists sessions, attempts, events, and aggregate scores', function (): void {
    $databasePath = sys_get_temp_dir() . '/php-llm-benchy-tests/db-' . uniqid('', true) . '.sqlite';
    if (!is_dir(dirname($databasePath))) {
        mkdir(dirname($databasePath), 0755, true);
    }

    putenv('DATABASE_PATH=' . $databasePath);
    $_ENV['DATABASE_PATH'] = $databasePath;

    $config = new AppConfig(dirname(__DIR__, 2));
    $database = new Database($config);
    $database->migrate();

    $repository = new SessionRepository($database->pdo());
    $session = $repository->createSession(
        'ollama',
        ['llama3.2', 'qwen2.5'],
        'llama3.2',
        ['tool_use', 'poem'],
        2,
        4242,
    );

    expect($session['provider'])->toBe('ollama')
        ->and($session['models'])->toHaveCount(2)
        ->and($session['benchmarks'])->toHaveCount(2);

    $repository->markSessionRunning($session['id']);

    $attemptId = $repository->createAttempt($session['id'], 'llama3.2', 'tool_use', 1, 'Use the tool and answer with the total.');
    $repository->appendEvent($session['id'], $attemptId, 'tool_call', ['name' => 'add_numbers', 'arguments' => ['numbers' => '17,25,8']]);
    $repository->completeAttempt(
        $attemptId,
        'captured',
        'The total is 50.',
        'I used the provided tool first.',
        ['used_required_tool' => true],
        [],
        ['prompt_tokens' => 10, 'completion_tokens' => 20],
        ['tool_names' => ['add_numbers']],
        50.0,
        0.0,
        50.0,
    );
    $repository->updateAttemptScores($attemptId, [
        'relevance' => 9,
        'coherence' => 8,
        'creativity' => 6,
        'accuracy' => 9,
        'fluency' => 8,
        'total' => 40,
        'notes' => 'Crisp and correct.',
    ], 40.0, 90.0);

    $repository->replaceBenchmarkScore($session['id'], 'llama3.2', 'tool_use', 90.0, 50.0, 40.0, 1, ['attempt_ids' => [$attemptId]]);
    $repository->replaceModelScore($session['id'], 'llama3.2', 90.0, 1, 1, ['benchmark_ids' => ['tool_use']]);
    $repository->markSessionCompleted($session['id']);

    $stored = $repository->getSession($session['id']);
    $events = $repository->listSessionEvents($session['id']);
    $leaderboard = $repository->overallLeaderboard();
    $exportRows = $repository->sessionExportRows($session['id']);

    expect($stored)->not->toBeNull();
    /** @var array<string, mixed> $stored */

    expect($stored)->not->toBeNull()
        ->and($stored['status'])->toBe('completed')
        ->and($stored['attempts'])->toHaveCount(1)
        ->and((float) $stored['attempts'][0]['total_score'])->toBe(90.0)
        ->and((float) $stored['model_scores'][0]['overall_score'])->toBe(90.0)
        ->and($events)->toHaveCount(1)
        ->and($leaderboard)->toHaveCount(1)
        ->and($leaderboard[0]['model_id'])->toBe('llama3.2')
        ->and($exportRows)->toHaveCount(1);
});