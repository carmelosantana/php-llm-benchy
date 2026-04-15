<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPLLMBenchy\Http;

use CarmeloSantana\PHPLLMBenchy\Benchmark\BenchmarkRegistry;
use CarmeloSantana\PHPLLMBenchy\Config\AppConfig;
use CarmeloSantana\PHPLLMBenchy\Repository\SessionRepository;
use CarmeloSantana\PHPLLMBenchy\Runner\BenchmarkRunner;
use CarmeloSantana\PHPLLMBenchy\Runner\ModelProviderFactory;

final readonly class App
{
    public function __construct(
        private AppConfig $config,
        private SessionRepository $repository,
        private BenchmarkRegistry $benchmarks,
        private ModelProviderFactory $providerFactory,
        private BenchmarkRunner $runner,
    ) {}

    public function handle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        if ($method === 'GET' && $path === '/') {
            $this->html($this->renderIndex());

            return;
        }

        if ($method === 'GET' && $path === '/api/config') {
            $this->json([
                'app_name' => $this->config->appName(),
                'defaults' => [
                    'provider' => $this->config->defaultProvider(),
                    'seed' => $this->config->defaultSeed(),
                    'runs_per_benchmark' => $this->config->defaultRunsPerBenchmark(),
                ],
                'providers' => $this->config->supportedProviders(),
                'benchmarks' => $this->benchmarks->catalog(),
            ]);

            return;
        }

        if ($method === 'GET' && $path === '/api/models') {
            $provider = (string) ($_GET['provider'] ?? $this->config->defaultProvider());
            $this->json([
                'provider' => $provider,
                'available' => $this->providerFactory->isAvailable($provider),
                'models' => $this->providerFactory->models($provider),
            ]);

            return;
        }

        if ($method === 'GET' && $path === '/api/sessions') {
            $this->json(['sessions' => $this->repository->listSessions()]);

            return;
        }

        if ($method === 'POST' && $path === '/api/sessions') {
            $payload = $this->requestJson();
            $provider = (string) ($payload['provider'] ?? $this->config->defaultProvider());
            $models = array_values(array_filter((array) ($payload['models'] ?? []), static fn(mixed $value): bool => is_string($value) && $value !== ''));
            $evaluationModel = (string) ($payload['evaluation_model'] ?? '');
            $benchmarks = array_values(array_filter((array) ($payload['benchmarks'] ?? []), static fn(mixed $value): bool => is_string($value) && $value !== ''));
            $runs = max(1, (int) ($payload['runs_per_benchmark'] ?? $this->config->defaultRunsPerBenchmark()));
            $seed = array_key_exists('seed', $payload) && $payload['seed'] !== null && $payload['seed'] !== '' ? (int) $payload['seed'] : $this->config->defaultSeed();

            if ($models === []) {
                $this->json(['error' => 'Select at least one model.'], 422);

                return;
            }

            if ($evaluationModel === '') {
                $this->json(['error' => 'Select an evaluation model.'], 422);

                return;
            }

            if ($benchmarks === []) {
                $this->json(['error' => 'Select at least one benchmark.'], 422);

                return;
            }

            $session = $this->repository->createSession(
                $provider,
                $models,
                $evaluationModel,
                $benchmarks,
                $runs,
                $seed,
            );

            $this->json(['session' => $session], 201);

            return;
        }

        if ($method === 'GET' && preg_match('#^/api/sessions/([^/]+)$#', $path, $matches) === 1) {
            $session = $this->repository->getSession($matches[1]);
            if ($session === null) {
                $this->json(['error' => 'Session not found.'], 404);

                return;
            }

            $this->json(['session' => $session]);

            return;
        }

        if ($method === 'GET' && preg_match('#^/api/sessions/([^/]+)/events$#', $path, $matches) === 1) {
            $limit = max(1, min(1000, (int) ($_GET['limit'] ?? 300)));
            $attemptId = isset($_GET['attempt_id']) ? (string) $_GET['attempt_id'] : null;

            $this->json([
                'events' => $this->repository->listSessionEvents($matches[1], $attemptId, $limit),
            ]);

            return;
        }

        if ($method === 'GET' && $path === '/api/leaderboard') {
            $this->json(['leaderboard' => $this->repository->overallLeaderboard()]);

            return;
        }

        if ($method === 'GET' && $path === '/api/export') {
            $sessionId = (string) ($_GET['session_id'] ?? '');
            if ($sessionId === '') {
                $this->json(['error' => 'Missing session_id.'], 422);

                return;
            }

            $this->streamCsv($sessionId);

            return;
        }

        if ($method === 'GET' && $path === '/api/run') {
            $sessionId = (string) ($_GET['session_id'] ?? '');
            if ($sessionId === '') {
                $this->json(['error' => 'Missing session_id.'], 422);

                return;
            }

            $this->streamRun($sessionId);

            return;
        }

        $this->json(['error' => 'Not found.'], 404);
    }

    private function requestJson(): array
    {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw ?: '{}', true);

        return is_array($decoded) ? $decoded : [];
    }

    private function streamRun(string $sessionId): void
    {
        ignore_user_abort(true);
        set_time_limit(0);

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        $stream = function (string $eventType, array $envelope): void {
            echo 'event: ' . $eventType . "\n";
            echo 'data: ' . json_encode($envelope, JSON_UNESCAPED_SLASHES) . "\n\n";

            if (function_exists('ob_get_level')) {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
            }

            flush();
        };

        try {
            $this->runner->runSession($sessionId, $stream);
            $stream('end', ['session_id' => $sessionId, 'status' => 'completed']);
        } catch (\Throwable $e) {
            $stream('fatal', ['session_id' => $sessionId, 'message' => $e->getMessage()]);
        }
    }

    private function streamCsv(string $sessionId): void
    {
        $rows = $this->repository->sessionExportRows($sessionId);

        header('Content-Type: text/csv; charset=utf-8');
        header(sprintf('Content-Disposition: attachment; filename="benchy-%s.csv"', $sessionId));

        $handle = fopen('php://output', 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open CSV output stream.');
        }

        fputcsv($handle, [
            'session_id', 'provider', 'model_id', 'benchmark_id', 'run_number', 'status',
            'capability_score', 'quality_score', 'total_score', 'started_at', 'finished_at',
            'response_text', 'reasoning_text', 'deterministic_json', 'rubric_json', 'usage_json', 'metrics_json',
        ]);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['session_id'],
                $row['provider'],
                $row['model_id'],
                $row['benchmark_id'],
                $row['run_number'],
                $row['status'],
                $row['capability_score'],
                $row['quality_score'],
                $row['total_score'],
                $row['started_at'],
                $row['finished_at'],
                $row['response_text'],
                $row['reasoning_text'],
                $row['deterministic_json'],
                $row['rubric_json'],
                $row['usage_json'],
                $row['metrics_json'],
            ]);
        }

        fclose($handle);
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    private function html(string $html): void
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
    }

    private function renderIndex(): string
    {
        $title = htmlspecialchars($this->config->appName(), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/basecoat-css@0.3.11/dist/basecoat.cdn.min.css">
    <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
    <link rel="stylesheet" href="/assets/app.css">
    <script defer src="/assets/app.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body x-data="benchyApp()" x-init="init()" class="benchy-body">
    <div class="benchy-shell">
        <aside class="benchy-sidebar" :class="{ 'is-open': sidebarOpen }">
            <nav aria-label="Session navigation">
                <section class="scrollbar sidebar-section">
                    <div class="sidebar-topbar">
                        <div class="brand-block">
                            <h1>PHP LLM Benchy</h1>
                            <div class="brand-copy-wrap" :class="{ 'is-collapsed': !showBrandCopy }" :aria-hidden="(!showBrandCopy).toString()">
                                <p class="brand-copy">Benchmark live sessions, compare model scores, inspect raw traces.</p>
                            </div>
                        </div>
                        <button class="btn btn-secondary btn-icon mobile-only" type="button" @click="sidebarOpen = false">
                            <span>×</span>
                        </button>
                    </div>

                    <div class="card session-list-card">
                        <div class="card-header compact-row">
                            <div>
                                <h2>Sessions</h2>
                                <p>Load previous benchmark runs.</p>
                            </div>
                            <button class="btn btn-secondary btn-sm" @click="refreshSessionsFromUser()">Refresh</button>
                        </div>

                        <div class="session-list" x-show="sessions.length > 0">
                            <template x-for="session in sessions" :key="session.id">
                                <button type="button" class="session-item" :class="{ 'is-active': selectedSession && selectedSession.id === session.id }" @click="selectSession(session.id)">
                                    <div class="session-item-main">
                                        <strong x-text="session.name"></strong>
                                        <span class="session-meta" x-text="session.provider + ' • ' + session.status"></span>
                                    </div>
                                    <div class="session-item-side">
                                        <span class="badge" x-text="session.completed_attempt_count + '/' + session.attempt_count"></span>
                                    </div>
                                </button>
                            </template>
                        </div>

                        <div class="empty" x-show="sessions.length === 0">
                            <p>No sessions yet.</p>
                        </div>
                    </div>
                </section>
            </nav>
        </aside>

        <main class="benchy-main">
            <section class="leaderboard-strip card">
                <div class="card-header compact-row">
                    <div class="leaderboard-heading">
                        <button type="button" class="btn btn-secondary btn-icon mobile-only" @click="sidebarOpen = true">
                            <span>☰</span>
                        </button>
                        <div>
                        <h3>Overall Leaders</h3>
                        <p>Average overall score across completed sessions.</p>
                        </div>
                    </div>
                    <a class="btn btn-secondary" :href="selectedSession ? ('/api/export?session_id=' + selectedSession.id) : '#'" :class="{ 'is-disabled': !selectedSession }">Export CSV</a>
                </div>
                <div class="leaderboard-grid">
                    <template x-for="entry in leaderboard.slice(0, 6)" :key="entry.provider + ':' + entry.model_id">
                        <article class="leader-card">
                            <div class="leader-top">
                                <span class="badge" x-text="entry.provider"></span>
                                <strong x-text="entry.average_score + '/100'"></strong>
                            </div>
                            <p class="leader-model" x-text="entry.model_id"></p>
                            <p class="leader-sub" x-text="entry.session_count + ' session(s)'"></p>
                        </article>
                    </template>
                </div>
            </section>

            <section class="benchy-grid">
                <section class="stack-col">
                    <article class="card form-card">
                        <div class="card-header">
                            <div>
                                <h3>New Session</h3>
                                <p>Choose the provider, models, evaluator, benchmarks, run count, and seed.</p>
                            </div>
                        </div>

                        <form class="form-grid" @submit.prevent="createAndRunSession()">
                            <label class="field">
                                <span>Provider</span>
                                <select class="select" x-model="form.provider" @change="loadModels()">
                                    <template x-for="provider in providers" :key="provider.id">
                                        <option :value="provider.id" x-text="provider.name"></option>
                                    </template>
                                </select>
                            </label>

                            <label class="field">
                                <span>Runs per benchmark</span>
                                <input class="input" type="number" min="1" max="10" x-model.number="form.runs_per_benchmark">
                            </label>

                            <label class="field">
                                <span>Seed</span>
                                <input class="input" type="number" x-model="form.seed" placeholder="Optional reproducibility seed">
                            </label>

                            <div class="field field-block">
                                <span>Models</span>
                                <p class="field-help">Loaded dynamically from the selected provider.</p>
                                <div class="selection-grid">
                                    <template x-for="model in availableModels" :key="model.id">
                                        <label class="choice-pill" :class="{ 'is-selected': form.models.includes(model.id) }">
                                            <input type="checkbox" :value="model.id" x-model="form.models">
                                            <span>
                                                <strong x-text="model.name || model.id"></strong>
                                                <small x-text="model.reasoning ? 'Reasoning enabled' : 'Standard generation'"></small>
                                            </span>
                                        </label>
                                    </template>
                                </div>
                            </div>

                            <label class="field field-block">
                                <span>Evaluation model</span>
                                <select class="select" x-model="form.evaluation_model">
                                    <option value="">Select evaluator</option>
                                    <template x-for="model in availableModels" :key="'eval-' + model.id">
                                        <option :value="model.id" x-text="model.name || model.id"></option>
                                    </template>
                                </select>
                            </label>

                            <div class="field field-block">
                                <span>Benchmarks</span>
                                <div class="selection-grid benchmark-grid">
                                    <template x-for="benchmark in benchmarks" :key="benchmark.id">
                                        <label class="choice-pill choice-pill-benchmark" :class="{ 'is-selected': form.benchmarks.includes(benchmark.id) }">
                                            <input type="checkbox" :value="benchmark.id" x-model="form.benchmarks">
                                            <span>
                                                <strong x-text="benchmark.name"></strong>
                                                <small x-text="benchmark.description"></small>
                                            </span>
                                        </label>
                                    </template>
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary" :disabled="creatingSession || running">
                                    <span x-text="creatingSession ? 'Creating…' : (running ? 'Running…' : 'Create and Run')"></span>
                                </button>
                            </div>
                        </form>
                    </article>

                    <article class="card console-card">
                        <div class="card-header compact-row">
                            <div>
                                <h3>Live Console</h3>
                                <p>Streaming text, reasoning, tool labels, and run status.</p>
                            </div>
                            <span class="badge" x-text="currentStatus"></span>
                        </div>

                        <div class="console-layout">
                            <div class="console-panel">
                                <h4>Events</h4>
                                <div class="console-stream" id="event-stream">
                                    <template x-for="event in liveEvents" :key="event.key">
                                        <div class="console-line">
                                            <span class="console-event" x-text="event.event_type"></span>
                                            <span class="console-payload" x-text="event.summary"></span>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <div class="console-panel">
                                <h4>Current Thinking</h4>
                                <pre class="console-output" x-text="liveReasoning"></pre>
                            </div>

                            <div class="console-panel">
                                <h4>Current Output</h4>
                                <pre class="console-output" x-text="liveOutput"></pre>
                            </div>
                        </div>
                    </article>

                    <article class="card session-detail-card" x-show="selectedSession">
                        <div class="card-header compact-row">
                            <div>
                                <h3 x-text="selectedSession ? selectedSession.name : 'Session detail'"></h3>
                                <p x-text="selectedSession ? (selectedSession.provider + ' • ' + selectedSession.status) : ''"></p>
                            </div>
                            <button class="btn btn-secondary btn-sm" @click="selectedSession && refreshSession(selectedSession.id)">Reload</button>
                        </div>

                        <div class="detail-grid" x-show="selectedSession">
                            <section>
                                <h4>Benchmark Scores</h4>
                                <div class="table-wrap">
                                    <table class="table session-table">
                                        <thead>
                                            <tr>
                                                <th>Model</th>
                                                <th>Benchmark</th>
                                                <th>Avg</th>
                                                <th>Capability</th>
                                                <th>Quality</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <template x-for="row in (selectedSession?.benchmark_scores || [])" :key="row.model_id + '-' + row.benchmark_id">
                                                <tr>
                                                    <td x-text="row.model_id"></td>
                                                    <td x-text="row.benchmark_id"></td>
                                                    <td x-text="row.average_score"></td>
                                                    <td x-text="row.capability_average"></td>
                                                    <td x-text="row.quality_average"></td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </section>

                            <section>
                                <h4>Attempts</h4>
                                <div class="attempt-list">
                                    <template x-for="attempt in (selectedSession?.attempts || [])" :key="attempt.id">
                                        <button type="button" class="attempt-card" :class="{ 'is-active': selectedAttempt && selectedAttempt.id === attempt.id }" @click="selectAttempt(attempt)">
                                            <div class="attempt-top">
                                                <strong x-text="attempt.model_id + ' • ' + attempt.benchmark_id"></strong>
                                                <span class="badge" x-text="attempt.total_score + '/100'"></span>
                                            </div>
                                            <p class="attempt-meta" x-text="'Run ' + attempt.run_number + ' • ' + attempt.status"></p>
                                        </button>
                                    </template>
                                </div>
                            </section>
                        </div>

                        <div class="attempt-detail" x-show="selectedAttempt">
                            <div class="detail-toolbar compact-row">
                                <h4 x-text="selectedAttempt ? ('Attempt ' + selectedAttempt.id) : ''"></h4>
                                <button class="btn btn-secondary btn-sm" @click="loadAttemptEvents(selectedAttempt.id)">Load trace</button>
                            </div>

                            <div class="detail-split">
                                <div>
                                    <h5>Response</h5>
                                    <pre class="response-box" x-text="selectedAttempt?.response_text || ''"></pre>
                                </div>
                                <div>
                                    <h5>Reasoning</h5>
                                    <pre class="response-box" x-text="selectedAttempt?.reasoning_text || ''"></pre>
                                </div>
                            </div>

                            <div class="detail-split compact-bottom">
                                <div>
                                    <h5>Deterministic Checks</h5>
                                    <pre class="response-box" x-text="formatJson(selectedAttempt?.deterministic || {})"></pre>
                                </div>
                                <div>
                                    <h5>Rubric</h5>
                                    <pre class="response-box" x-text="formatJson(selectedAttempt?.rubric || {})"></pre>
                                </div>
                            </div>

                            <div>
                                <h5>Trace Events</h5>
                                <div class="trace-list">
                                    <template x-for="event in selectedAttemptEvents" :key="event.id">
                                        <div class="trace-item">
                                            <span class="badge" x-text="event.event_type"></span>
                                            <pre x-text="formatJson(event.payload)"></pre>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </article>
                </section>

                <aside class="stack-col right-rail">
                    <article class="card model-rail-card">
                        <div class="card-header">
                            <div>
                                <h3>Session Models</h3>
                                <p>Compare the overall 100-point score for each model in the current session.</p>
                            </div>
                        </div>

                        <div class="model-rail-list" x-show="selectedSession && (selectedSession.model_scores || []).length > 0">
                            <template x-for="model in (selectedSession?.model_scores || [])" :key="model.model_id">
                                <article class="model-score-card">
                                    <div class="compact-row">
                                        <strong x-text="model.model_id"></strong>
                                        <span class="badge badge-strong" x-text="model.overall_score + '/100'"></span>
                                    </div>
                                    <div class="progress" role="progressbar" :aria-valuenow="model.overall_score" aria-valuemin="0" aria-valuemax="100">
                                        <div class="progress-bar" :style="'width:' + model.overall_score + '%' "></div>
                                    </div>
                                    <p class="model-score-sub" x-text="model.benchmark_count + ' benchmark(s) • ' + model.total_runs + ' run(s)'"></p>
                                </article>
                            </template>
                        </div>

                        <div class="empty" x-show="!selectedSession || (selectedSession.model_scores || []).length === 0">
                            <p>Run a session to populate model rankings.</p>
                        </div>
                    </article>
                </aside>
            </section>
        </main>
    </div>
</body>
</html>
HTML;
    }
}