<?php

declare(strict_types=1);

use CarmeloSantana\PHPLLMBenchy\Config\AppConfig;
use CarmeloSantana\PHPLLMBenchy\Config\SeedFrequency;
use CarmeloSantana\PHPLLMBenchy\Config\SeedType;
use CarmeloSantana\PHPLLMBenchy\Config\SessionSeedConfiguration;
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
        new SessionSeedConfiguration(4242, SeedType::Iterative, SeedFrequency::PerRun),
    );

    expect($session['provider'])->toBe('ollama')
        ->and($session['models'])->toHaveCount(2)
        ->and($session['benchmarks'])->toHaveCount(2)
        ->and($session['seed'])->toBe(4242)
        ->and($session['seed_type'])->toBe('iterative')
        ->and($session['seed_frequency'])->toBe('per_run')
        ->and($session['config']['seed_type'])->toBe('iterative')
        ->and($session['config']['seed_frequency'])->toBe('per_run');

    $repository->markSessionRunning($session['id']);

    $attemptId = $repository->createAttempt($session['id'], 'llama3.2', 'tool_use', 1, 'Use the tool and answer with the total.', 4243);
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
    if ($stored === null) {
        throw new RuntimeException('Expected stored session.');
    }

    expect($stored)->not->toBeNull()
        ->and($stored['status'])->toBe('completed')
        ->and($stored['attempts'])->toHaveCount(1)
        ->and($stored['attempts'][0]['effective_seed'])->toBe(4243)
        ->and((float) $stored['attempts'][0]['total_score'])->toBe(90.0)
        ->and((float) $stored['model_scores'][0]['overall_score'])->toBe(90.0)
        ->and($events)->toHaveCount(1)
        ->and($leaderboard)->toHaveCount(1)
        ->and($leaderboard[0]['model_id'])->toBe('llama3.2')
        ->and($exportRows)->toHaveCount(1)
        ->and((int) $exportRows[0]['effective_seed'])->toBe(4243);
});

it('builds dashboard aggregates including synthetic mario analytics', function (): void {
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
        ['mario_speedrun_synthetic', 'tool_use'],
        1,
        new SessionSeedConfiguration(7, SeedType::Fixed, SeedFrequency::PerSession),
    );

    $repository->markSessionRunning($session['id']);

    $marioSuccessAttempt = $repository->createAttempt($session['id'], 'llama3.2', 'mario_speedrun_synthetic', 1, 'Finish fast.', 7);
    $repository->completeAttempt(
        $marioSuccessAttempt,
        'captured',
        'Completed in 220 frames.',
        'Read state and jumped early.',
        ['completed' => true],
        [],
        ['prompt_tokens' => 15, 'completion_tokens' => 12],
        ['synthetic_mario' => [
            'completed' => true,
            'frames_used' => 220,
            'checkpoints_cleared' => 5,
            'checkpoint_count' => 5,
            'deaths' => 0,
            'invalid_actions' => 0,
            'failure_reason' => null,
        ]],
        48.0,
        0.0,
        48.0,
    );
    $repository->updateAttemptScores($marioSuccessAttempt, ['total' => 44], 44.0, 92.0);

    $marioFailAttempt = $repository->createAttempt($session['id'], 'qwen2.5', 'mario_speedrun_synthetic', 1, 'Finish fast.', 7);
    $repository->completeAttempt(
        $marioFailAttempt,
        'captured',
        'Hit the Goomba.',
        'Ran without enough jump height.',
        ['completed' => false],
        [],
        ['prompt_tokens' => 14, 'completion_tokens' => 10],
        ['synthetic_mario' => [
            'completed' => false,
            'frames_used' => 16,
            'checkpoints_cleared' => 0,
            'checkpoint_count' => 5,
            'deaths' => 1,
            'invalid_actions' => 1,
            'failure_reason' => 'collision_with_goomba',
        ]],
        4.0,
        0.0,
        4.0,
    );
    $repository->updateAttemptScores($marioFailAttempt, ['total' => 20], 20.0, 24.0);

    $toolAttempt = $repository->createAttempt($session['id'], 'llama3.2', 'tool_use', 1, 'Use the tool.', 7);
    $repository->completeAttempt(
        $toolAttempt,
        'captured',
        'The total is 50.',
        'Used add_numbers.',
        ['used_required_tool' => true],
        [],
        ['prompt_tokens' => 5, 'completion_tokens' => 6],
        ['tool_names' => ['add_numbers']],
        50.0,
        0.0,
        50.0,
    );
    $repository->updateAttemptScores($toolAttempt, ['total' => 40], 40.0, 90.0);

    $repository->replaceBenchmarkScore($session['id'], 'llama3.2', 'mario_speedrun_synthetic', 92.0, 48.0, 44.0, 1, ['attempt_ids' => [$marioSuccessAttempt]]);
    $repository->replaceBenchmarkScore($session['id'], 'qwen2.5', 'mario_speedrun_synthetic', 24.0, 4.0, 20.0, 1, ['attempt_ids' => [$marioFailAttempt]]);
    $repository->replaceBenchmarkScore($session['id'], 'llama3.2', 'tool_use', 90.0, 50.0, 40.0, 1, ['attempt_ids' => [$toolAttempt]]);
    $repository->replaceModelScore($session['id'], 'llama3.2', 91.0, 2, 2, ['benchmark_ids' => ['mario_speedrun_synthetic', 'tool_use']]);
    $repository->replaceModelScore($session['id'], 'qwen2.5', 24.0, 1, 1, ['benchmark_ids' => ['mario_speedrun_synthetic']]);
    $repository->markSessionCompleted($session['id']);

    $overview = $repository->dashboardOverview();
    $recentSessions = $repository->recentSessions();
    $benchmarkComparison = $repository->benchmarkComparison();
    $marioAnalytics = $repository->syntheticMarioAnalytics();

    expect($overview['total_sessions'])->toBe(1)
        ->and($overview['completed_sessions'])->toBe(1)
        ->and($overview['total_attempts'])->toBe(3)
        ->and($overview['unique_models'])->toBe(2)
        ->and($overview['average_overall_score'])->toBe(57.5)
        ->and($recentSessions)->toHaveCount(1)
        ->and($recentSessions[0]['top_model_id'])->toBe('llama3.2')
        ->and($recentSessions[0]['average_score'])->toBe(57.5)
        ->and($benchmarkComparison)->toHaveCount(3)
        ->and($marioAnalytics['overview']['total_runs'])->toBe(2)
        ->and($marioAnalytics['overview']['completed_runs'])->toBe(1)
        ->and($marioAnalytics['overview']['completion_rate'])->toBe(50.0)
        ->and($marioAnalytics['overview']['failure_reasons']['collision_with_goomba'])->toBe(1)
        ->and($marioAnalytics['models'])->toHaveCount(2)
        ->and($marioAnalytics['models'][0]['model_id'])->toBe('llama3.2');
});

it('supports paused, resumed, and stopped session states', function (): void {
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
    $session = $repository->createSession('ollama', ['llama3.2'], 'llama3.2', ['tool_use'], 1, new SessionSeedConfiguration(7, SeedType::Fixed, SeedFrequency::PerSession));

    $repository->markSessionRunning($session['id']);
    $repository->markSessionPaused($session['id']);
    expect($repository->sessionStatus($session['id']))->toBe('paused');

    $repository->markSessionResumed($session['id']);
    expect($repository->sessionStatus($session['id']))->toBe('running');

    $repository->markSessionStopped($session['id'], 'Stopped by user.');

    $stored = $repository->getSession($session['id']);
    expect($stored)->not->toBeNull()
        ;
    if ($stored === null) {
        throw new RuntimeException('Expected stored session.');
    }

    expect($stored['status'])->toBe('stopped')
        ->and($stored['error_message'])->toBe('Stopped by user.')
        ->and($stored['completed_at'])->not->toBeNull();
});