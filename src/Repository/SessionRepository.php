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