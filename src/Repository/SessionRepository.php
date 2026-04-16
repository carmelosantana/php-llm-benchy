<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPLLMBenchy\Repository;

use CarmeloSantana\PHPLLMBenchy\Support\Ids;
use CarmeloSantana\PHPLLMBenchy\Support\Json;
use PDO;

final class SessionRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {}

    public function createSession(
        string $provider,
        array $models,
        string $evaluationModel,
        array $benchmarks,
        int $runsPerBenchmark,
        ?int $seed,
    ): array {
        $sessionId = Ids::session();
        $now = gmdate('c');
        $name = sprintf('Session %s', gmdate('Y-m-d H:i:s'));
        $config = [
            'provider' => $provider,
            'models' => array_values($models),
            'evaluation_model' => $evaluationModel,
            'benchmarks' => array_values($benchmarks),
            'runs_per_benchmark' => $runsPerBenchmark,
            'seed' => $seed,
        ];

        $this->pdo->beginTransaction();

        $stmt = $this->pdo->prepare(
            'INSERT INTO sessions (id, name, provider, evaluation_model, runs_per_benchmark, seed, status, config_json, created_at, updated_at) VALUES (:id, :name, :provider, :evaluation_model, :runs_per_benchmark, :seed, :status, :config_json, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':id' => $sessionId,
            ':name' => $name,
            ':provider' => $provider,
            ':evaluation_model' => $evaluationModel,
            ':runs_per_benchmark' => $runsPerBenchmark,
            ':seed' => $seed,
            ':status' => 'draft',
            ':config_json' => Json::encode($config),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $insertModel = $this->pdo->prepare(
            'INSERT INTO session_models (session_id, model_id, sort_order) VALUES (:session_id, :model_id, :sort_order)'
        );
        foreach (array_values($models) as $index => $modelId) {
            $insertModel->execute([
                ':session_id' => $sessionId,
                ':model_id' => $modelId,
                ':sort_order' => $index,
            ]);
        }

        $insertBenchmark = $this->pdo->prepare(
            'INSERT INTO session_benchmarks (session_id, benchmark_id, sort_order, weight) VALUES (:session_id, :benchmark_id, :sort_order, :weight)'
        );
        foreach (array_values($benchmarks) as $index => $benchmark) {
            $benchmarkId = is_array($benchmark) ? (string) $benchmark['id'] : (string) $benchmark;
            $weight = is_array($benchmark) ? (float) ($benchmark['weight'] ?? 1.0) : 1.0;
            $insertBenchmark->execute([
                ':session_id' => $sessionId,
                ':benchmark_id' => $benchmarkId,
                ':sort_order' => $index,
                ':weight' => $weight,
            ]);
        }

        $this->pdo->commit();

        return $this->getSession($sessionId) ?? [];
    }

    public function listSessions(): array
    {
        $stmt = $this->pdo->query(<<<'SQL'
SELECT
    s.id,
    s.name,
    s.provider,
    s.evaluation_model,
    s.runs_per_benchmark,
    s.seed,
    s.status,
    s.error_message,
    s.created_at,
    s.updated_at,
    (SELECT COUNT(*) FROM session_models sm WHERE sm.session_id = s.id) AS model_count,
    (SELECT COUNT(*) FROM session_benchmarks sb WHERE sb.session_id = s.id) AS benchmark_count,
    (SELECT COUNT(*) FROM attempts a WHERE a.session_id = s.id) AS attempt_count,
    (SELECT COUNT(*) FROM attempts a WHERE a.session_id = s.id AND a.status = 'completed') AS completed_attempt_count
FROM sessions s
ORDER BY s.created_at DESC
SQL);

        if ($stmt === false) {
            throw new \RuntimeException('Failed to fetch sessions.');
        }

        return $stmt->fetchAll();
    }

    public function getSession(string $sessionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sessions WHERE id = :id');
        $stmt->execute([':id' => $sessionId]);
        $session = $stmt->fetch();

        if ($session === false) {
            return null;
        }

        $session['config'] = Json::decode((string) $session['config_json']);
        unset($session['config_json']);

        $session['models'] = $this->listSessionModels($sessionId);
        $session['benchmarks'] = $this->listSessionBenchmarks($sessionId);
        $session['attempts'] = $this->listAttempts($sessionId);
        $session['benchmark_scores'] = $this->listBenchmarkScores($sessionId);
        $session['model_scores'] = $this->listModelScores($sessionId);

        return $session;
    }

    public function listSessionModels(string $sessionId): array
    {
        $stmt = $this->pdo->prepare('SELECT model_id, sort_order FROM session_models WHERE session_id = :session_id ORDER BY sort_order ASC');
        $stmt->execute([':session_id' => $sessionId]);

        return $stmt->fetchAll();
    }

    public function listSessionBenchmarks(string $sessionId): array
    {
        $stmt = $this->pdo->prepare('SELECT benchmark_id, sort_order, weight FROM session_benchmarks WHERE session_id = :session_id ORDER BY sort_order ASC');
        $stmt->execute([':session_id' => $sessionId]);

        return $stmt->fetchAll();
    }

    public function markSessionRunning(string $sessionId): void
    {
        $this->updateSessionStatus($sessionId, 'running', ['started_at' => gmdate('c'), 'error_message' => null]);
    }

    public function markSessionEvaluating(string $sessionId): void
    {
        $this->updateSessionStatus($sessionId, 'evaluating');
    }

    public function markSessionPaused(string $sessionId): void
    {
        $this->updateSessionStatus($sessionId, 'paused');
    }

    public function markSessionResumed(string $sessionId): void
    {
        $this->updateSessionStatus($sessionId, 'running');
    }

    public function markSessionStopped(string $sessionId, ?string $message = null): void
    {
        $this->updateSessionStatus($sessionId, 'stopped', [
            'completed_at' => gmdate('c'),
            'error_message' => $message,
        ]);
    }

    public function markSessionCompleted(string $sessionId): void
    {
        $this->updateSessionStatus($sessionId, 'completed', ['completed_at' => gmdate('c')]);
    }

    public function markSessionFailed(string $sessionId, string $error): void
    {
        $this->updateSessionStatus($sessionId, 'failed', [
            'completed_at' => gmdate('c'),
            'error_message' => $error,
        ]);
    }

    public function createAttempt(string $sessionId, string $modelId, string $benchmarkId, int $runNumber, string $prompt): string
    {
        $attemptId = Ids::attempt();
        $stmt = $this->pdo->prepare(
            'INSERT INTO attempts (id, session_id, model_id, benchmark_id, run_number, status, prompt, started_at) VALUES (:id, :session_id, :model_id, :benchmark_id, :run_number, :status, :prompt, :started_at)'
        );
        $stmt->execute([
            ':id' => $attemptId,
            ':session_id' => $sessionId,
            ':model_id' => $modelId,
            ':benchmark_id' => $benchmarkId,
            ':run_number' => $runNumber,
            ':status' => 'running',
            ':prompt' => $prompt,
            ':started_at' => gmdate('c'),
        ]);

        return $attemptId;
    }

    public function completeAttempt(
        string $attemptId,
        string $status,
        string $responseText,
        string $reasoningText,
        array $deterministic,
        array $rubric,
        array $usage,
        array $metrics,
        float $capabilityScore,
        float $qualityScore,
        float $totalScore,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE attempts SET status = :status, response_text = :response_text, reasoning_text = :reasoning_text, deterministic_json = :deterministic_json, rubric_json = :rubric_json, usage_json = :usage_json, metrics_json = :metrics_json, capability_score = :capability_score, quality_score = :quality_score, total_score = :total_score, finished_at = :finished_at WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $attemptId,
            ':status' => $status,
            ':response_text' => $responseText,
            ':reasoning_text' => $reasoningText,
            ':deterministic_json' => Json::encode($deterministic),
            ':rubric_json' => Json::encode($rubric),
            ':usage_json' => Json::encode($usage),
            ':metrics_json' => Json::encode($metrics),
            ':capability_score' => $capabilityScore,
            ':quality_score' => $qualityScore,
            ':total_score' => $totalScore,
            ':finished_at' => gmdate('c'),
        ]);
    }

    public function updateAttemptScores(string $attemptId, array $rubric, float $qualityScore, float $totalScore, string $status = 'completed'): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE attempts SET status = :status, rubric_json = :rubric_json, quality_score = :quality_score, total_score = :total_score, finished_at = COALESCE(finished_at, :finished_at) WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $attemptId,
            ':status' => $status,
            ':rubric_json' => Json::encode($rubric),
            ':quality_score' => $qualityScore,
            ':total_score' => $totalScore,
            ':finished_at' => gmdate('c'),
        ]);
    }

    public function appendEvent(string $sessionId, ?string $attemptId, string $eventType, array $payload): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO attempt_events (session_id, attempt_id, event_type, payload_json, created_at) VALUES (:session_id, :attempt_id, :event_type, :payload_json, :created_at)'
        );
        $stmt->execute([
            ':session_id' => $sessionId,
            ':attempt_id' => $attemptId,
            ':event_type' => $eventType,
            ':payload_json' => Json::encode($payload),
            ':created_at' => gmdate('c'),
        ]);
    }

    public function listSessionEvents(string $sessionId, ?string $attemptId = null, int $limit = 500): array
    {
        if ($attemptId === null) {
            $stmt = $this->pdo->prepare('SELECT id, attempt_id, event_type, payload_json, created_at FROM attempt_events WHERE session_id = :session_id ORDER BY id DESC LIMIT :limit');
            $stmt->bindValue(':session_id', $sessionId);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $this->pdo->prepare('SELECT id, attempt_id, event_type, payload_json, created_at FROM attempt_events WHERE session_id = :session_id AND attempt_id = :attempt_id ORDER BY id DESC LIMIT :limit');
            $stmt->bindValue(':session_id', $sessionId);
            $stmt->bindValue(':attempt_id', $attemptId);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
        }

        $events = $stmt->fetchAll();

        return array_map(function (array $event): array {
            $event['payload'] = Json::decode((string) $event['payload_json']);
            unset($event['payload_json']);

            return $event;
        }, array_reverse($events));
    }

    public function sessionStatus(string $sessionId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM sessions WHERE id = :id');
        $stmt->execute([':id' => $sessionId]);
        $status = $stmt->fetchColumn();

        return is_string($status) ? $status : null;
    }

    public function replaceBenchmarkScore(string $sessionId, string $modelId, string $benchmarkId, float $averageScore, float $capabilityAverage, float $qualityAverage, int $runs, array $summary): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO benchmark_scores (session_id, model_id, benchmark_id, average_score, capability_average, quality_average, runs, summary_json) VALUES (:session_id, :model_id, :benchmark_id, :average_score, :capability_average, :quality_average, :runs, :summary_json) ON CONFLICT(session_id, model_id, benchmark_id) DO UPDATE SET average_score = excluded.average_score, capability_average = excluded.capability_average, quality_average = excluded.quality_average, runs = excluded.runs, summary_json = excluded.summary_json'
        );
        $stmt->execute([
            ':session_id' => $sessionId,
            ':model_id' => $modelId,
            ':benchmark_id' => $benchmarkId,
            ':average_score' => $averageScore,
            ':capability_average' => $capabilityAverage,
            ':quality_average' => $qualityAverage,
            ':runs' => $runs,
            ':summary_json' => Json::encode($summary),
        ]);
    }

    public function replaceModelScore(string $sessionId, string $modelId, float $overallScore, int $benchmarkCount, int $totalRuns, array $summary): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO model_scores (session_id, model_id, overall_score, benchmark_count, total_runs, summary_json) VALUES (:session_id, :model_id, :overall_score, :benchmark_count, :total_runs, :summary_json) ON CONFLICT(session_id, model_id) DO UPDATE SET overall_score = excluded.overall_score, benchmark_count = excluded.benchmark_count, total_runs = excluded.total_runs, summary_json = excluded.summary_json'
        );
        $stmt->execute([
            ':session_id' => $sessionId,
            ':model_id' => $modelId,
            ':overall_score' => $overallScore,
            ':benchmark_count' => $benchmarkCount,
            ':total_runs' => $totalRuns,
            ':summary_json' => Json::encode($summary),
        ]);
    }

    public function listAttempts(string $sessionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM attempts WHERE session_id = :session_id ORDER BY model_id ASC, benchmark_id ASC, run_number ASC');
        $stmt->execute([':session_id' => $sessionId]);
        $attempts = $stmt->fetchAll();

        return array_map(function (array $attempt): array {
            $attempt['deterministic'] = Json::decode((string) $attempt['deterministic_json']);
            $attempt['rubric'] = Json::decode((string) $attempt['rubric_json']);
            $attempt['usage'] = Json::decode((string) $attempt['usage_json']);
            $attempt['metrics'] = Json::decode((string) $attempt['metrics_json']);
            unset($attempt['deterministic_json'], $attempt['rubric_json'], $attempt['usage_json'], $attempt['metrics_json']);

            return $attempt;
        }, $attempts);
    }

    public function listBenchmarkScores(string $sessionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM benchmark_scores WHERE session_id = :session_id ORDER BY model_id ASC, benchmark_id ASC');
        $stmt->execute([':session_id' => $sessionId]);
        $rows = $stmt->fetchAll();

        return array_map(function (array $row): array {
            $row['summary'] = Json::decode((string) $row['summary_json']);
            unset($row['summary_json']);

            return $row;
        }, $rows);
    }

    public function listModelScores(string $sessionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM model_scores WHERE session_id = :session_id ORDER BY overall_score DESC, model_id ASC');
        $stmt->execute([':session_id' => $sessionId]);
        $rows = $stmt->fetchAll();

        return array_map(function (array $row): array {
            $row['summary'] = Json::decode((string) $row['summary_json']);
            unset($row['summary_json']);

            return $row;
        }, $rows);
    }

    public function overallLeaderboard(): array
    {
        $stmt = $this->pdo->query(<<<'SQL'
SELECT
    s.provider,
    ms.model_id,
    ROUND(AVG(ms.overall_score), 2) AS average_score,
    COUNT(*) AS session_count,
    MAX(s.completed_at) AS last_completed_at
FROM model_scores ms
JOIN sessions s ON s.id = ms.session_id
WHERE s.status = 'completed'
GROUP BY s.provider, ms.model_id
ORDER BY average_score DESC, session_count DESC, ms.model_id ASC
SQL);

        if ($stmt === false) {
            throw new \RuntimeException('Failed to fetch leaderboard.');
        }

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, int|float>
     */
    public function dashboardOverview(): array
    {
        $stmt = $this->pdo->query(<<<'SQL'
SELECT
    (SELECT COUNT(*) FROM sessions) AS total_sessions,
    (SELECT COUNT(*) FROM sessions WHERE status = 'completed') AS completed_sessions,
    (SELECT COUNT(*) FROM sessions WHERE status IN ('running', 'evaluating', 'paused')) AS active_sessions,
    (SELECT COUNT(*) FROM sessions WHERE status = 'failed') AS failed_sessions,
    (SELECT COUNT(*) FROM attempts) AS total_attempts,
    (SELECT COUNT(*) FROM attempts WHERE status = 'completed') AS completed_attempts,
    (SELECT COUNT(DISTINCT model_id) FROM model_scores) AS unique_models,
    COALESCE((
        SELECT ROUND(AVG(ms.overall_score), 2)
        FROM model_scores ms
        JOIN sessions s ON s.id = ms.session_id
        WHERE s.status = 'completed'
    ), 0) AS average_overall_score
SQL);

        if ($stmt === false) {
            throw new \RuntimeException('Failed to fetch dashboard overview.');
        }

        $row = $stmt->fetch();
        if ($row === false) {
            return [
                'total_sessions' => 0,
                'completed_sessions' => 0,
                'active_sessions' => 0,
                'failed_sessions' => 0,
                'total_attempts' => 0,
                'completed_attempts' => 0,
                'unique_models' => 0,
                'average_overall_score' => 0.0,
            ];
        }

        return [
            'total_sessions' => (int) $row['total_sessions'],
            'completed_sessions' => (int) $row['completed_sessions'],
            'active_sessions' => (int) $row['active_sessions'],
            'failed_sessions' => (int) $row['failed_sessions'],
            'total_attempts' => (int) $row['total_attempts'],
            'completed_attempts' => (int) $row['completed_attempts'],
            'unique_models' => (int) $row['unique_models'],
            'average_overall_score' => (float) $row['average_overall_score'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentSessions(int $limit = 8): array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
SELECT
    s.id,
    s.name,
    s.provider,
    s.status,
    s.created_at,
    s.completed_at,
    (SELECT COUNT(*) FROM attempts a WHERE a.session_id = s.id) AS attempt_count,
    (SELECT COUNT(*) FROM attempts a WHERE a.session_id = s.id AND a.status = 'completed') AS completed_attempt_count,
    COALESCE((SELECT ROUND(AVG(ms.overall_score), 2) FROM model_scores ms WHERE ms.session_id = s.id), 0) AS average_score,
    (SELECT ms.model_id FROM model_scores ms WHERE ms.session_id = s.id ORDER BY ms.overall_score DESC, ms.model_id ASC LIMIT 1) AS top_model_id,
    (SELECT ms.overall_score FROM model_scores ms WHERE ms.session_id = s.id ORDER BY ms.overall_score DESC, ms.model_id ASC LIMIT 1) AS top_model_score
FROM sessions s
ORDER BY s.created_at DESC
LIMIT :limit
SQL);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static function (array $row): array {
            $row['attempt_count'] = (int) $row['attempt_count'];
            $row['completed_attempt_count'] = (int) $row['completed_attempt_count'];
            $row['average_score'] = (float) $row['average_score'];
            $row['top_model_score'] = $row['top_model_score'] !== null ? (float) $row['top_model_score'] : null;

            return $row;
        }, $stmt->fetchAll());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function benchmarkComparison(): array
    {
        $stmt = $this->pdo->query(<<<'SQL'
SELECT
    bs.benchmark_id,
    bs.model_id,
    ROUND(AVG(bs.average_score), 2) AS average_score,
    ROUND(AVG(bs.capability_average), 2) AS capability_average,
    ROUND(AVG(bs.quality_average), 2) AS quality_average,
    SUM(bs.runs) AS total_runs,
    COUNT(*) AS session_count
FROM benchmark_scores bs
JOIN sessions s ON s.id = bs.session_id
WHERE s.status = 'completed'
GROUP BY bs.benchmark_id, bs.model_id
ORDER BY bs.benchmark_id ASC, average_score DESC, bs.model_id ASC
SQL);

        if ($stmt === false) {
            throw new \RuntimeException('Failed to fetch benchmark comparison data.');
        }

        return array_map(static function (array $row): array {
            $row['average_score'] = (float) $row['average_score'];
            $row['capability_average'] = (float) $row['capability_average'];
            $row['quality_average'] = (float) $row['quality_average'];
            $row['total_runs'] = (int) $row['total_runs'];
            $row['session_count'] = (int) $row['session_count'];

            return $row;
        }, $stmt->fetchAll());
    }

    /**
     * @return array<string, mixed>
     */
    public function syntheticMarioAnalytics(): array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
SELECT a.model_id, a.total_score, a.metrics_json
FROM attempts a
JOIN sessions s ON s.id = a.session_id
WHERE s.status = 'completed'
  AND a.benchmark_id = :benchmark_id
ORDER BY a.model_id ASC, a.run_number ASC
SQL);
        $stmt->execute([':benchmark_id' => 'mario_speedrun_synthetic']);
        $rows = $stmt->fetchAll();

        $overview = [
            'total_runs' => 0,
            'completed_runs' => 0,
            'completion_rate' => 0.0,
            'average_frames_completed' => null,
            'average_deaths' => 0.0,
            'average_invalid_actions' => 0.0,
            'average_checkpoints_cleared' => 0.0,
            'failure_reasons' => [],
        ];

        $framesTotal = 0;
        $framesCount = 0;
        $deathsTotal = 0;
        $invalidTotal = 0;
        $checkpointsTotal = 0;
        $models = [];

        foreach ($rows as $row) {
            $metrics = Json::decode((string) $row['metrics_json']);
            $summary = $metrics['synthetic_mario'] ?? null;
            if (!is_array($summary)) {
                continue;
            }

            $modelId = (string) $row['model_id'];
            $completed = ($summary['completed'] ?? false) === true;
            $framesUsed = (int) ($summary['frames_used'] ?? 0);
            $deaths = (int) ($summary['deaths'] ?? 0);
            $invalidActions = (int) ($summary['invalid_actions'] ?? 0);
            $checkpointsCleared = (int) ($summary['checkpoints_cleared'] ?? 0);
            $failureReason = (string) ($summary['failure_reason'] ?? '');

            $overview['total_runs']++;
            $deathsTotal += $deaths;
            $invalidTotal += $invalidActions;
            $checkpointsTotal += $checkpointsCleared;

            if ($completed) {
                $overview['completed_runs']++;
                $framesTotal += $framesUsed;
                $framesCount++;
            } elseif ($failureReason !== '') {
                $overview['failure_reasons'][$failureReason] = ($overview['failure_reasons'][$failureReason] ?? 0) + 1;
            }

            if (!isset($models[$modelId])) {
                $models[$modelId] = [
                    'model_id' => $modelId,
                    'runs' => 0,
                    'completed_runs' => 0,
                    'completion_rate' => 0.0,
                    'average_frames_completed' => null,
                    'average_deaths' => 0.0,
                    'average_invalid_actions' => 0.0,
                    'average_checkpoints_cleared' => 0.0,
                    'average_total_score' => 0.0,
                    'failure_reasons' => [],
                    '_frames_total' => 0,
                    '_frames_count' => 0,
                    '_deaths_total' => 0,
                    '_invalid_total' => 0,
                    '_checkpoints_total' => 0,
                    '_score_total' => 0.0,
                ];
            }

            $models[$modelId]['runs']++;
            $models[$modelId]['_deaths_total'] += $deaths;
            $models[$modelId]['_invalid_total'] += $invalidActions;
            $models[$modelId]['_checkpoints_total'] += $checkpointsCleared;
            $models[$modelId]['_score_total'] += (float) $row['total_score'];

            if ($completed) {
                $models[$modelId]['completed_runs']++;
                $models[$modelId]['_frames_total'] += $framesUsed;
                $models[$modelId]['_frames_count']++;
            } elseif ($failureReason !== '') {
                $models[$modelId]['failure_reasons'][$failureReason] = ($models[$modelId]['failure_reasons'][$failureReason] ?? 0) + 1;
            }
        }

        if ($overview['total_runs'] > 0) {
            $overview['completion_rate'] = round(($overview['completed_runs'] / $overview['total_runs']) * 100, 2);
            $overview['average_deaths'] = round($deathsTotal / $overview['total_runs'], 2);
            $overview['average_invalid_actions'] = round($invalidTotal / $overview['total_runs'], 2);
            $overview['average_checkpoints_cleared'] = round($checkpointsTotal / $overview['total_runs'], 2);
        }

        if ($framesCount > 0) {
            $overview['average_frames_completed'] = round($framesTotal / $framesCount, 2);
        }

        $modelRows = array_values(array_map(static function (array $row): array {
            $row['completion_rate'] = $row['runs'] > 0
                ? round(($row['completed_runs'] / $row['runs']) * 100, 2)
                : 0.0;
            $row['average_deaths'] = $row['runs'] > 0 ? round($row['_deaths_total'] / $row['runs'], 2) : 0.0;
            $row['average_invalid_actions'] = $row['runs'] > 0 ? round($row['_invalid_total'] / $row['runs'], 2) : 0.0;
            $row['average_checkpoints_cleared'] = $row['runs'] > 0 ? round($row['_checkpoints_total'] / $row['runs'], 2) : 0.0;
            $row['average_total_score'] = $row['runs'] > 0 ? round($row['_score_total'] / $row['runs'], 2) : 0.0;
            $row['average_frames_completed'] = $row['_frames_count'] > 0
                ? round($row['_frames_total'] / $row['_frames_count'], 2)
                : null;

            unset(
                $row['_frames_total'],
                $row['_frames_count'],
                $row['_deaths_total'],
                $row['_invalid_total'],
                $row['_checkpoints_total'],
                $row['_score_total'],
            );

            return $row;
        }, $models));

        usort($modelRows, static function (array $left, array $right): int {
            return [$right['completion_rate'], $left['average_total_score'], $left['model_id']]
                <=> [$left['completion_rate'], $right['average_total_score'], $right['model_id']];
        });

        arsort($overview['failure_reasons']);

        return [
            'overview' => $overview,
            'models' => $modelRows,
        ];
    }

    public function sessionExportRows(string $sessionId): array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
SELECT
    a.session_id,
    s.provider,
    a.model_id,
    a.benchmark_id,
    a.run_number,
    a.status,
    a.capability_score,
    a.quality_score,
    a.total_score,
    a.started_at,
    a.finished_at,
    a.response_text,
    a.reasoning_text,
    a.deterministic_json,
    a.rubric_json,
    a.usage_json,
    a.metrics_json
FROM attempts a
JOIN sessions s ON s.id = a.session_id
WHERE a.session_id = :session_id
ORDER BY a.model_id ASC, a.benchmark_id ASC, a.run_number ASC
SQL);
        $stmt->execute([':session_id' => $sessionId]);

        return $stmt->fetchAll();
    }

    private function updateSessionStatus(string $sessionId, string $status, array $extra = []): void
    {
        $sets = ['status = :status', 'updated_at = :updated_at'];
        $params = [
            ':status' => $status,
            ':updated_at' => gmdate('c'),
            ':id' => $sessionId,
        ];

        foreach ($extra as $column => $value) {
            $sets[] = sprintf('%s = :%s', $column, $column);
            $params[':' . $column] = $value;
        }

        $sql = sprintf('UPDATE sessions SET %s WHERE id = :id', implode(', ', $sets));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }
}