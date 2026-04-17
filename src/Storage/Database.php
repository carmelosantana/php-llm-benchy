<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPLLMBenchy\Storage;

use CarmeloSantana\PHPLLMBenchy\Config\AppConfig;
use PDO;

final class Database
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly AppConfig $config,
    ) {}

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dbPath = $this->config->databasePath();
        $directory = dirname($dbPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA journal_mode = WAL');

        return $this->pdo;
    }

    public function migrate(): void
    {
        $pdo = $this->pdo();

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS sessions (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    provider TEXT NOT NULL,
    evaluation_model TEXT NOT NULL,
    runs_per_benchmark INTEGER NOT NULL,
    seed INTEGER NULL,
    seed_type TEXT NOT NULL DEFAULT 'fixed',
    seed_frequency TEXT NOT NULL DEFAULT 'per_session',
    status TEXT NOT NULL,
    config_json TEXT NOT NULL,
    error_message TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    started_at TEXT NULL,
    completed_at TEXT NULL
)
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS session_models (
    session_id TEXT NOT NULL,
    model_id TEXT NOT NULL,
    sort_order INTEGER NOT NULL,
    PRIMARY KEY (session_id, model_id),
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
)
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS session_benchmarks (
    session_id TEXT NOT NULL,
    benchmark_id TEXT NOT NULL,
    sort_order INTEGER NOT NULL,
    weight REAL NOT NULL DEFAULT 1,
    PRIMARY KEY (session_id, benchmark_id),
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
)
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS attempts (
    id TEXT PRIMARY KEY,
    session_id TEXT NOT NULL,
    model_id TEXT NOT NULL,
    benchmark_id TEXT NOT NULL,
    run_number INTEGER NOT NULL,
    effective_seed INTEGER NULL,
    status TEXT NOT NULL,
    prompt TEXT NOT NULL,
    response_text TEXT NOT NULL DEFAULT '',
    reasoning_text TEXT NOT NULL DEFAULT '',
    deterministic_json TEXT NOT NULL DEFAULT '{}',
    rubric_json TEXT NOT NULL DEFAULT '{}',
    usage_json TEXT NOT NULL DEFAULT '{}',
    metrics_json TEXT NOT NULL DEFAULT '{}',
    capability_score REAL NOT NULL DEFAULT 0,
    quality_score REAL NOT NULL DEFAULT 0,
    total_score REAL NOT NULL DEFAULT 0,
    started_at TEXT NOT NULL,
    finished_at TEXT NULL,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
)
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS attempt_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id TEXT NOT NULL,
    attempt_id TEXT NULL,
    event_type TEXT NOT NULL,
    payload_json TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (attempt_id) REFERENCES attempts(id) ON DELETE CASCADE
)
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS benchmark_scores (
    session_id TEXT NOT NULL,
    model_id TEXT NOT NULL,
    benchmark_id TEXT NOT NULL,
    average_score REAL NOT NULL,
    capability_average REAL NOT NULL,
    quality_average REAL NOT NULL,
    runs INTEGER NOT NULL,
    summary_json TEXT NOT NULL DEFAULT '{}',
    PRIMARY KEY (session_id, model_id, benchmark_id),
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
)
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS model_scores (
    session_id TEXT NOT NULL,
    model_id TEXT NOT NULL,
    overall_score REAL NOT NULL,
    benchmark_count INTEGER NOT NULL,
    total_runs INTEGER NOT NULL,
    summary_json TEXT NOT NULL DEFAULT '{}',
    PRIMARY KEY (session_id, model_id),
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
)
SQL);

        $this->ensureColumn($pdo, 'sessions', 'seed_type', "TEXT NOT NULL DEFAULT 'fixed'");
        $this->ensureColumn($pdo, 'sessions', 'seed_frequency', "TEXT NOT NULL DEFAULT 'per_session'");
        $this->ensureColumn($pdo, 'attempts', 'effective_seed', 'INTEGER NULL');

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attempts_session ON attempts(session_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attempts_session_model ON attempts(session_id, model_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_session ON attempt_events(session_id, id)');
    }

    private function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        $stmt = $pdo->query(sprintf('PRAGMA table_info(%s)', $table));
        if ($stmt === false) {
            throw new \RuntimeException(sprintf('Unable to inspect schema for table "%s".', $table));
        }

        foreach ($stmt->fetchAll() as $row) {
            if (($row['name'] ?? null) === $column) {
                return;
            }
        }

        $pdo->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
    }
}