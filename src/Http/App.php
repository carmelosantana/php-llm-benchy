<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPLLMBenchy\Http;

use CarmeloSantana\PHPLLMBenchy\Benchmark\BenchmarkRegistry;
use CarmeloSantana\PHPLLMBenchy\Config\AppConfig;
use CarmeloSantana\PHPLLMBenchy\Config\SeedFrequency;
use CarmeloSantana\PHPLLMBenchy\Config\SeedType;
use CarmeloSantana\PHPLLMBenchy\Config\SessionSeedConfigurationValidator;
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

        if ($method === 'GET' && $path === '/dashboard') {
            $this->html($this->renderDashboardPage());

            return;
        }

        if ($method === 'GET' && $path === '/api/config') {
            $this->json([
                'app_name' => $this->config->appName(),
                'defaults' => [
                    'provider' => $this->config->defaultProvider(),
                    'seed' => $this->config->defaultSeed(),
                    'seed_type' => $this->config->defaultSeedType()->value,
                    'seed_frequency' => $this->config->defaultSeedFrequency()->value,
                    'runs_per_benchmark' => $this->config->defaultRunsPerBenchmark(),
                ],
                'seed_types' => array_map(static fn(SeedType $type): array => ['id' => $type->value, 'name' => $type->label()], SeedType::cases()),
                'seed_frequencies' => array_map(static fn(SeedFrequency $frequency): array => ['id' => $frequency->value, 'name' => $frequency->label()], SeedFrequency::cases()),
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
            $limit = isset($_GET['limit']) ? max(1, min(200, (int) $_GET['limit'])) : null;

            $this->json([
                'sessions' => $this->repository->listSessions($limit),
                'total' => $this->repository->countSessions(),
            ]);

            return;
        }

        if ($method === 'POST' && $path === '/api/sessions') {
            $payload = $this->requestJson();
            $provider = (string) ($payload['provider'] ?? $this->config->defaultProvider());
            $models = array_values(array_filter((array) ($payload['models'] ?? []), static fn(mixed $value): bool => is_string($value) && $value !== ''));
            $evaluationModel = (string) ($payload['evaluation_model'] ?? '');
            $benchmarks = array_values(array_filter((array) ($payload['benchmarks'] ?? []), static fn(mixed $value): bool => is_string($value) && $value !== ''));
            $runs = max(1, (int) ($payload['runs_per_benchmark'] ?? $this->config->defaultRunsPerBenchmark()));

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

            try {
                $seedConfiguration = (new SessionSeedConfigurationValidator($this->config))
                    ->resolve($payload, count($models), count($benchmarks), $runs);
            } catch (\InvalidArgumentException $e) {
                $this->json(['error' => $e->getMessage()], 422);

                return;
            }

            $session = $this->repository->createSession(
                $provider,
                $models,
                $evaluationModel,
                $benchmarks,
                $runs,
                $seedConfiguration,
            );

            $this->json(['session' => $session], 201);

            return;
        }

        if ($method === 'GET' && preg_match('#^/api/sessions/([^/]+)$#', $path, $matches) === 1) {
            $attemptLimit = isset($_GET['attempt_limit']) ? max(1, min(500, (int) $_GET['attempt_limit'])) : null;
            $session = $this->repository->getSession($matches[1], $attemptLimit);
            if ($session === null) {
                $this->json(['error' => 'Session not found.'], 404);

                return;
            }

            $this->json(['session' => $session]);

            return;
        }

        if ($method === 'POST' && preg_match('#^/api/sessions/([^/]+)/(pause|resume|stop)$#', $path, $matches) === 1) {
            $sessionId = $matches[1];
            $action = $matches[2];
            $session = $this->repository->getSession($sessionId);

            if ($session === null) {
                $this->json(['error' => 'Session not found.'], 404);

                return;
            }

            $status = (string) ($session['status'] ?? '');

            if ($action === 'pause') {
                if ($status !== 'running') {
                    $this->json(['error' => 'Only running sessions can be paused.'], 409);

                    return;
                }

                $this->repository->markSessionPaused($sessionId);
            }

            if ($action === 'resume') {
                if ($status !== 'paused') {
                    $this->json(['error' => 'Only paused sessions can be resumed.'], 409);

                    return;
                }

                $this->repository->markSessionResumed($sessionId);
            }

            if ($action === 'stop') {
                if (in_array($status, ['completed', 'failed', 'stopped'], true)) {
                    $this->json(['error' => 'This session is already finished.'], 409);

                    return;
                }

                $this->repository->markSessionStopped($sessionId, 'Stopped by user.');
            }

            $this->json([
                'action' => $action,
                'session' => $this->repository->getSession($sessionId),
            ]);

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

        if ($method === 'GET' && $path === '/api/dashboard') {
            $this->json($this->dashboardPayload());

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

        if ($method === 'GET' && preg_match('#^/sessions/([^/]+)$#', $path, $matches) === 1) {
            $session = $this->repository->getSession($matches[1]);
            if ($session === null) {
                http_response_code(404);
                $this->html($this->renderNotFoundPage('Session not found.'));

                return;
            }

            $this->html($this->renderSessionDetailPage((string) $session['id'], (string) $session['name']));

            return;
        }

        $this->json(['error' => 'Not found.'], 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboardPayload(): array
    {
        $benchmarkLabels = [];
        foreach ($this->benchmarks->all() as $definition) {
            $benchmarkLabels[$definition->id] = $definition->name;
        }

        return [
            'overview' => $this->repository->dashboardOverview(),
            'leaderboard' => $this->repository->overallLeaderboard(),
            'recent_sessions' => $this->repository->recentSessions(),
            'benchmark_comparison' => $this->repository->benchmarkComparison(),
            'mario_analytics' => $this->repository->syntheticMarioAnalytics(),
            'benchmark_labels' => $benchmarkLabels,
        ];
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
            $stream('end', [
                'session_id' => $sessionId,
                'status' => $this->repository->sessionStatus($sessionId) ?? 'unknown',
            ]);
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
            'effective_seed', 'capability_score', 'quality_score', 'total_score', 'started_at', 'finished_at',
            'response_text', 'reasoning_text', 'deterministic_json', 'rubric_json', 'usage_json', 'metrics_json',
        ], ',', '"', '\\');

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['session_id'],
                $row['provider'],
                $row['model_id'],
                $row['benchmark_id'],
                $row['run_number'],
                $row['status'],
                $row['effective_seed'],
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
            ], ',', '"', '\\');
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
        $content = <<<HTML
    <div class="benchy-shell" x-data="benchyApp()" x-init="init()">
        <aside class="benchy-sidebar" :class="{ 'is-open': sidebarOpen }">
            <nav aria-label="Session navigation">
                <section class="sidebar-section">
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
                            <template x-for="session in visibleSessions()" :key="session.id">
                                <button type="button" class="session-item" :class="{ 'is-active': selectedSession && selectedSession.id === session.id }" @click="selectSession(session.id)">
                                    <span class="session-item-main">
                                        <strong x-text="timeAgo(session.name, sessionsRefreshedAt)"></strong>
                                        <span class="session-meta" x-text="session.provider + ' • ' + formatStatusLabel(session.status) + ' • ' + seedPolicyLabel(session)"></span>
                                    </span>
                                    <span class="session-item-side">
                                        <span class="badge" x-text="session.completed_attempt_count + '/' + session.attempt_count"></span>
                                    </span>
                                </button>
                            </template>

                            <div class="list-actions" x-show="hasMoreSessions()">
                                <button type="button" class="btn btn-secondary btn-sm" @click="showMoreSessions()">Show more sessions</button>
                            </div>
                        </div>

                        <div class="empty" x-show="sessions.length === 0">
                            <p>No sessions yet.</p>
                        </div>
                    </div>

                    <p class="sidebar-attribution">
                        Built with <a href="https://github.com/carmelosantana/php-agents" target="_blank" rel="noreferrer"><code>php-agents</code></a>
                    </p>
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
                        <h3>Leaders</h3>
                        <p>Quick summary of top models. Open the dashboard for full analytics.</p>
                        </div>
                    </div>
                    <div class="header-actions">
                        <a class="btn btn-secondary" href="/dashboard">Dashboard</a>
                        <a class="btn btn-secondary" :href="selectedSession ? ('/api/export?session_id=' + selectedSession.id) : '#'" :class="{ 'is-disabled': !selectedSession }">Export CSV</a>
                    </div>
                </div>
                <div class="leaderboard-grid">
                    <template x-for="entry in leaderboard.slice(0, 3)" :key="entry.provider + ':' + entry.model_id">
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
                                <p>Choose the provider, models, evaluator, benchmarks, run count, and seed policy.</p>
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
                                <input class="input" type="number" min="0" step="1" x-model="form.seed" :disabled="form.seed_type === 'random'" :placeholder="form.seed_type === 'random' ? 'Generated automatically' : 'Base or fixed seed'">
                                <p class="field-help" x-text="seedInputHelp()"></p>
                            </label>

                            <label class="field">
                                <span>Seed Type</span>
                                <select class="select" x-model="form.seed_type" @change="normalizeSeedControls()">
                                    <template x-for="seedType in seedTypes" :key="seedType.id">
                                        <option :value="seedType.id" x-text="seedType.name"></option>
                                    </template>
                                </select>
                            </label>

                            <label class="field">
                                <span>Seed Change Frequency</span>
                                <select class="select" x-model="form.seed_frequency" :disabled="form.seed_type === 'fixed'" @change="normalizeSeedControls()">
                                    <template x-for="frequency in seedFrequencies" :key="frequency.id">
                                        <option :value="frequency.id" x-text="frequency.name"></option>
                                    </template>
                                </select>
                                <p class="field-help" x-text="seedFrequencyHelp()"></p>
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
                                <button type="submit" class="btn btn-primary btn-lg" :disabled="creatingSession || running">
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
                            <div class="header-actions">
                                <button class="btn btn-secondary btn-sm" x-show="canPauseSelectedSession()" @click="controlSession('pause')">Pause</button>
                                <button class="btn btn-secondary btn-sm" x-show="canResumeSelectedSession()" @click="controlSession('resume')">Resume</button>
                                <button class="btn btn-secondary btn-sm" x-show="canStopSelectedSession()" @click="controlSession('stop')">Stop</button>
                                <span class="badge" x-show="activeSeed !== null" x-text="'Seed ' + formatSeed(activeSeed)"></span>
                                <span class="badge" x-text="currentStatus"></span>
                            </div>
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
                            <div class="header-actions">
                                <a class="btn btn-secondary btn-sm" :href="selectedSession ? ('/sessions/' + selectedSession.id) : '#'" :class="{ 'is-disabled': !selectedSession }">Open Page</a>
                                <button class="btn btn-secondary btn-sm" @click="selectedSession && refreshSession(selectedSession.id)">Reload</button>
                            </div>
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
                                            <template x-for="row in visibleSelectedBenchmarkScores()" :key="row.model_id + '-' + row.benchmark_id">
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
                                <div class="list-actions" x-show="hasMoreSelectedBenchmarkScores()">
                                    <button type="button" class="btn btn-secondary btn-sm" @click="showMoreSelectedBenchmarkScores()">Show more rows</button>
                                </div>
                            </section>

                            <section>
                                <h4>Attempts</h4>
                                <div class="attempt-list">
                                    <template x-for="attempt in visibleSelectedAttempts()" :key="attempt.id">
                                        <button type="button" class="attempt-card" :class="{ 'is-active': selectedAttempt && selectedAttempt.id === attempt.id }" @click="selectAttempt(attempt)">
                                            <div class="attempt-top">
                                                <strong x-text="attempt.model_id + ' • ' + attempt.benchmark_id"></strong>
                                                <span class="badge badge-strong" x-text="formatScore(attempt.total_score)"></span>
                                            </div>
                                            <p class="attempt-meta" x-text="'Run ' + attempt.run_number + ' • Seed ' + formatSeed(attempt.effective_seed) + ' • ' + formatStatusLabel(attempt.status) + ' • Cap: ' + formatScore(attempt.capability_score, 50) + ' • Qual: ' + formatScore(attempt.quality_score, 50)"></p>
                                        </button>
                                    </template>
                                </div>
                                <div class="list-actions" x-show="hasMoreSelectedAttempts()">
                                    <button type="button" class="btn btn-secondary btn-sm" @click="showMoreSelectedAttempts()">Show more attempts</button>
                                </div>
                            </section>
                        </div>

                        <div class="attempt-detail" x-show="selectedAttempt">
                            <div class="detail-toolbar compact-row">
                                <h4 x-text="selectedAttempt ? ('Attempt ' + selectedAttempt.id) : ''"></h4>
                                <button class="btn btn-secondary btn-sm" @click="loadAttemptEvents(selectedAttempt.id)">
                                    <span x-text="selectedAttemptEvents.length > 0 ? 'Refresh trace' : 'Load trace'"></span>
                                </button>
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

                            <div class="trace-section">
                                <div class="detail-toolbar compact-row">
                                    <h5>Trace Events</h5>
                                    <button
                                        type="button"
                                        class="btn btn-secondary btn-sm"
                                        :disabled="selectedAttemptEvents.length === 0"
                                        @click="traceExpanded = !traceExpanded"
                                        x-text="traceExpanded ? 'Collapse' : 'Expand'"
                                    ></button>
                                </div>
                                <div class="trace-viewport" :class="{ 'is-expanded': traceExpanded }">
                                    <div class="trace-list" x-show="selectedAttemptEvents.length > 0">
                                        <template x-for="event in selectedAttemptEvents" :key="event.id">
                                            <div class="trace-item">
                                                <span class="badge" x-text="event.event_type"></span>
                                                <pre x-text="formatJson(event.payload)"></pre>
                                            </div>
                                        </template>
                                    </div>
                                    <div class="empty" x-show="selectedAttemptEvents.length === 0">
                                        <p>Load a trace to inspect recorded attempt events.</p>
                                    </div>
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

            <footer class="benchy-footer">
                <p class="footer-copy">MIT License © 2026 Carmelo Santana.</p>
            </footer>
        </main>
    </div>
HTML;

        return $this->renderDocument($title, $content, ['/assets/app.js']);
    }

    private function renderDashboardPage(): string
    {
        $title = htmlspecialchars($this->config->appName() . ' Dashboard', ENT_QUOTES, 'UTF-8');
        $dashboardJsHref = $this->assetHref('/assets/dashboard.js');

        $content = <<<HTML
    <div class="page-shell" x-data="dashboardPage()" x-init="init()" x-cloak>
        <main class="benchy-main dashboard-main">
            <header class="card page-nav-card">
                <div class="page-nav-copy">
                    <span class="badge">Dashboard</span>
                    <h1>{$title}</h1>
                    <p>Cross-session leaderboard analytics, benchmark breakdowns, and benchmark-specific summaries.</p>
                </div>
                <div class="page-nav-links">
                    <a class="btn btn-secondary" href="/">Runner</a>
                </div>
            </header>

            <section class="stats-grid" x-show="overviewCards().length > 0">
                <template x-for="card in overviewCards()" :key="card.label">
                    <article class="card stat-card">
                        <span class="stat-label" x-text="card.label"></span>
                        <strong class="stat-value" x-text="card.value"></strong>
                        <p class="stat-copy" x-text="card.copy"></p>
                    </article>
                </template>
            </section>

            <section class="chart-grid chart-grid-primary">
                <article class="card chart-card">
                    <div class="card-header compact-row">
                        <div>
                            <h3>Overall Leaderboard</h3>
                            <p>Average overall score across completed sessions.</p>
                        </div>
                    </div>
                    <div class="chart-frame">
                        <canvas id="overallLeaderboardChart"></canvas>
                    </div>
                </article>

                <article class="card chart-card">
                    <div class="card-header compact-row">
                        <div>
                            <h3>Recent Sessions</h3>
                            <p>Average session performance over the most recent runs.</p>
                        </div>
                    </div>
                    <div class="chart-frame">
                        <canvas id="recentSessionsChart"></canvas>
                    </div>
                </article>
            </section>

            <section class="chart-grid chart-grid-secondary">
                <article class="card chart-card chart-card-wide">
                    <div class="card-header compact-row">
                        <div>
                            <h3>Benchmark Comparison</h3>
                            <p>Average benchmark score by model across completed sessions.</p>
                        </div>
                    </div>
                    <div class="chart-frame chart-frame-tall">
                        <canvas id="benchmarkComparisonChart"></canvas>
                    </div>
                </article>
            </section>

            <section class="card mario-dashboard-card" x-show="marioAnalytics?.overview?.total_runs > 0">
                <div class="card-header">
                    <div>
                        <h3>Synthetic Mario Benchmark</h3>
                        <p>Checkpoint progress, completion rates, and synthetic frame efficiency.</p>
                    </div>
                </div>

                <div class="stats-grid mario-stats-grid">
                    <template x-for="card in marioOverviewCards()" :key="card.label">
                        <article class="stat-card mario-stat-card">
                            <span class="stat-label" x-text="card.label"></span>
                            <strong class="stat-value" x-text="card.value"></strong>
                            <p class="stat-copy" x-text="card.copy"></p>
                        </article>
                    </template>
                </div>

                <div class="chart-grid mario-chart-grid">
                    <article class="chart-card mario-chart-card">
                        <div class="chart-frame">
                            <canvas id="marioCompletionChart"></canvas>
                        </div>
                    </article>
                    <article class="chart-card mario-chart-card">
                        <div class="chart-frame">
                            <canvas id="marioFramesChart"></canvas>
                        </div>
                    </article>
                </div>

                <div class="table-wrap">
                    <table class="table session-table">
                        <thead>
                            <tr>
                                <th>Model</th>
                                <th>Completion</th>
                                <th>Avg Frames</th>
                                <th>Avg Deaths</th>
                                <th>Avg Checkpoints</th>
                                <th>Avg Score</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="row in visibleMarioRows()" :key="row.model_id">
                                <tr>
                                    <td x-text="row.model_id"></td>
                                    <td x-text="row.completion_rate + '%' "></td>
                                    <td x-text="row.average_frames_completed ?? '—'"></td>
                                    <td x-text="row.average_deaths"></td>
                                    <td x-text="row.average_checkpoints_cleared"></td>
                                    <td x-text="row.average_total_score"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <div class="list-actions" x-show="hasMoreMarioRows()">
                    <button type="button" class="btn btn-secondary btn-sm" @click="showMoreMarioRows()">Show more models</button>
                </div>
            </section>

            <section class="card">
                <div class="card-header compact-row">
                    <div>
                        <h3>Recent Sessions</h3>
                        <p>Jump directly into completed or in-progress runs.</p>
                    </div>
                </div>
                <div class="dashboard-session-grid">
                    <template x-for="session in visibleRecentSessions()" :key="session.id">
                        <a class="session-link-card" :href="'/sessions/' + session.id">
                            <div class="compact-row">
                                <strong x-text="session.name"></strong>
                                <span class="badge" x-text="session.status"></span>
                            </div>
                            <p class="session-meta" x-text="session.provider + ' • ' + session.completed_attempt_count + '/' + session.attempt_count + ' attempts' "></p>
                            <p class="session-meta" x-text="session.top_model_id ? ('Top: ' + session.top_model_id + ' • ' + session.average_score + '/100') : 'No scored models yet'"></p>
                        </a>
                    </template>
                </div>
                <div class="list-actions" x-show="hasMoreRecentSessions()">
                    <button type="button" class="btn btn-secondary btn-sm" @click="showMoreRecentSessions()">Show more sessions</button>
                </div>
            </section>
        </main>
    </div>
HTML;

        return $this->renderDocument(
            $title,
            $content,
            ['/assets/dashboard.js'],
            ['https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js'],
        );
    }

    private function renderSessionDetailPage(string $sessionId, string $sessionName): string
    {
        $title = htmlspecialchars($sessionName . ' · Session Detail', ENT_QUOTES, 'UTF-8');
        $sessionIdJson = json_encode($sessionId, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        $content = <<<HTML
    <div class="page-shell" x-data='sessionDetailPage({$sessionIdJson})' x-init="init()" x-cloak>
        <main class="benchy-main detail-main">
            <header class="card page-nav-card">
                <div class="page-nav-copy">
                    <span class="badge">Session Detail</span>
                    <h1>{$title}</h1>
                    <p>Attempt details, trace events, reasoning, and benchmark-specific summaries.</p>
                </div>
                <div class="page-nav-links">
                    <a class="btn btn-secondary" href="/">Runner</a>
                    <a class="btn btn-secondary" href="/dashboard">Dashboard</a>
                    <a class="btn btn-secondary" :href="session ? ('/api/export?session_id=' + session.id) : '#'" :class="{ 'is-disabled': !session }">Export CSV</a>
                    <button class="btn btn-secondary" type="button" @click="refreshSession()">Refresh</button>
                </div>
            </header>

            <section class="card" x-show="session">
                <div class="card-header compact-row">
                    <div>
                        <h3 x-text="session?.name || ''"></h3>
                        <p x-text="session ? (session.provider + ' • ' + session.status + ' • ' + (session.models?.length || 0) + ' model(s)') : ''"></p>
                    </div>
                    <span class="badge badge-strong" x-text="session ? (((session.attempt_total ?? (session.attempts || []).length)) + ' attempt(s)') : ''"></span>
                </div>
                <div class="stats-grid session-overview-grid">
                    <template x-for="card in sessionOverviewCards()" :key="card.label">
                        <article class="stat-card">
                            <span class="stat-label" x-text="card.label"></span>
                            <strong class="stat-value" x-text="card.value"></strong>
                            <p class="stat-copy" x-text="card.copy"></p>
                        </article>
                    </template>
                </div>
            </section>

            <section class="detail-page-grid" x-show="session">
                <aside class="stack-col">
                    <article class="card model-rail-card">
                        <div class="card-header">
                            <div>
                                <h3>Session Models</h3>
                                <p>Overall scores for the selected session.</p>
                            </div>
                        </div>
                        <div class="model-rail-list" x-show="(session?.model_scores || []).length > 0">
                            <template x-for="model in (session?.model_scores || [])" :key="model.model_id">
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
                    </article>

                    <article class="card">
                        <div class="card-header">
                            <div>
                                <h3>Benchmark Scores</h3>
                                <p>Average benchmark performance for this session.</p>
                            </div>
                        </div>
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
                                    <template x-for="row in visibleBenchmarkScores()" :key="row.model_id + '-' + row.benchmark_id">
                                        <tr>
                                            <td x-text="row.model_id"></td>
                                            <td x-text="formatBenchmarkLabel(row.benchmark_id)"></td>
                                            <td x-text="row.average_score"></td>
                                            <td x-text="row.capability_average"></td>
                                            <td x-text="row.quality_average"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                        <div class="list-actions" x-show="hasMoreBenchmarkScores()">
                            <button type="button" class="btn btn-secondary btn-sm" @click="showMoreBenchmarkScores()">Show more rows</button>
                        </div>
                    </article>

                    <article class="card">
                        <div class="card-header">
                            <div>
                                <h3>Attempts</h3>
                                <p>Choose an attempt to inspect the full trace and benchmark summary.</p>
                            </div>
                        </div>
                        <div class="attempt-list">
                            <template x-for="attempt in visibleAttempts()" :key="attempt.id">
                                <button type="button" class="attempt-card" :class="{ 'is-active': selectedAttempt && selectedAttempt.id === attempt.id }" @click="selectAttempt(attempt)">
                                    <div class="attempt-top">
                                        <strong x-text="attempt.model_id + ' • ' + formatBenchmarkLabel(attempt.benchmark_id)"></strong>
                                        <span class="badge badge-strong" x-text="formatScore(attempt.total_score)"></span>
                                    </div>
                                    <p class="attempt-meta" x-text="'Run ' + attempt.run_number + ' • Seed ' + formatSeed(attempt.effective_seed) + ' • ' + formatStatusLabel(attempt.status) + ' • Cap: ' + formatScore(attempt.capability_score, 50) + ' • Qual: ' + formatScore(attempt.quality_score, 50)"></p>
                                </button>
                            </template>
                        </div>
                        <div class="list-actions" x-show="hasMoreAttempts()">
                            <button type="button" class="btn btn-secondary btn-sm" @click="showMoreAttempts()">Show more attempts</button>
                        </div>
                    </article>
                </aside>

                <section class="stack-col" x-show="selectedAttempt">
                    <article class="card">
                        <div class="card-header">
                            <div>
                                <h3>Benchmark Summary</h3>
                                <p x-text="selectedAttempt ? (selectedAttempt.model_id + ' • ' + formatBenchmarkLabel(selectedAttempt.benchmark_id)) : ''"></p>
                            </div>
                        </div>

                        <div class="summary-grid" x-show="!isMarioBenchmark(selectedAttempt)">
                            <template x-for="card in genericSummaryCards(selectedAttempt)" :key="card.label">
                                <article class="summary-card">
                                    <span class="stat-label" x-text="card.label"></span>
                                    <strong class="stat-value" x-text="card.value"></strong>
                                    <p class="stat-copy" x-text="card.copy"></p>
                                </article>
                            </template>
                        </div>

                        <div x-show="isMarioBenchmark(selectedAttempt)">
                            <div class="summary-grid mario-summary-grid">
                                <template x-for="card in marioSummaryCards(selectedAttempt)" :key="card.label">
                                    <article class="summary-card mario-summary-card">
                                        <span class="stat-label" x-text="card.label"></span>
                                        <strong class="stat-value" x-text="card.value"></strong>
                                        <p class="stat-copy" x-text="card.copy"></p>
                                    </article>
                                </template>
                            </div>

                            <div class="mario-progress-block">
                                <div class="compact-row">
                                    <strong>Checkpoint Progress</strong>
                                    <span class="badge" x-text="marioCheckpointLabel(selectedAttempt)"></span>
                                </div>
                                <div class="progress" role="progressbar" :aria-valuenow="marioCheckpointPercent(selectedAttempt)" aria-valuemin="0" aria-valuemax="100">
                                    <div class="progress-bar" :style="'width:' + marioCheckpointPercent(selectedAttempt) + '%' "></div>
                                </div>
                                <p class="session-meta" x-text="marioFailureCopy(selectedAttempt)"></p>
                            </div>

                            <div class="detail-split compact-bottom">
                                <div>
                                    <h5>Final State</h5>
                                    <pre class="response-box" x-text="formatJson(marioSummary(selectedAttempt)?.final_state || {})"></pre>
                                </div>
                                <div>
                                    <h5>Action Log</h5>
                                    <pre class="response-box" x-text="formatJson((marioSummary(selectedAttempt)?.action_log || []).slice(0, 12))"></pre>
                                </div>
                            </div>
                        </div>
                    </article>

                    <article class="card">
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
                    </article>

                    <article class="card">
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
                    </article>

                    <article class="card trace-section">
                        <div class="detail-toolbar compact-row">
                            <h5>Trace Events</h5>
                            <div class="header-actions">
                                <button class="btn btn-secondary btn-sm" @click="selectedAttempt && loadAttemptEvents(selectedAttempt.id)">Refresh Trace</button>
                                <button
                                    type="button"
                                    class="btn btn-secondary btn-sm"
                                    :disabled="selectedAttemptEvents.length === 0"
                                    @click="traceExpanded = !traceExpanded"
                                    x-text="traceExpanded ? 'Collapse' : 'Expand'"
                                ></button>
                            </div>
                        </div>
                        <div class="trace-viewport" :class="{ 'is-expanded': traceExpanded }">
                            <div class="trace-list" x-show="selectedAttemptEvents.length > 0">
                                <template x-for="event in selectedAttemptEvents" :key="event.id">
                                    <div class="trace-item">
                                        <span class="badge" x-text="event.event_type"></span>
                                        <pre x-text="formatJson(event.payload)"></pre>
                                    </div>
                                </template>
                            </div>
                            <div class="empty" x-show="selectedAttemptEvents.length === 0">
                                <p>Load a trace to inspect recorded attempt events.</p>
                            </div>
                        </div>
                    </article>
                </section>
            </section>
        </main>
    </div>
HTML;

        return $this->renderDocument($title, $content, ['/assets/session-detail.js']);
    }

    private function renderNotFoundPage(string $message): string
    {
        $title = htmlspecialchars($this->config->appName() . ' · Not Found', ENT_QUOTES, 'UTF-8');
        $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        return $this->renderDocument($title, <<<HTML
    <main class="benchy-main not-found-main">
        <section class="card empty-state-card">
            <span class="badge">Not Found</span>
            <h1>{$title}</h1>
            <p>{$message}</p>
            <div class="page-nav-links">
                <a class="btn btn-secondary" href="/">Runner</a>
                <a class="btn btn-secondary" href="/dashboard">Dashboard</a>
            </div>
        </section>
    </main>
HTML);
    }

    private function renderDocument(string $title, string $content, array $localScripts = [], array $externalScripts = []): string
    {
        $cssHref = $this->assetHref('/assets/app.css');
        $scriptTags = [];

        foreach ($externalScripts as $script) {
            $scriptTags[] = '<script defer src="' . htmlspecialchars($script, ENT_QUOTES, 'UTF-8') . '"></script>';
        }

        foreach ($localScripts as $scriptPath) {
            $scriptTags[] = '<script defer src="' . htmlspecialchars($this->assetHref($scriptPath), ENT_QUOTES, 'UTF-8') . '"></script>';
        }

        $scriptTags[] = '<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>';
        $scripts = implode("\n    ", $scriptTags);

        return <<<HTML
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
    <link rel="stylesheet" href="{$cssHref}">
    {$scripts}
</head>
<body class="benchy-body">
{$content}
</body>
</html>
HTML;
    }

    private function assetHref(string $publicPath): string
    {
        $assetPath = dirname(__DIR__, 2) . '/public' . $publicPath;
        $version = is_file($assetPath) ? (string) filemtime($assetPath) : (string) time();

        return $publicPath . '?v=' . rawurlencode($version);
    }
}