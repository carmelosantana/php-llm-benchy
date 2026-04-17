window.benchyApp = function benchyApp() {
    return {
        sidebarOpen: false,
        showBrandCopy: true,
        sessionsRefreshedAt: Date.now(),
        providers: [],
        benchmarks: [],
        seedTypes: [],
        seedFrequencies: [],
        availableModels: [],
        sessions: [],
        leaderboard: [],
        selectedSession: null,
        selectedAttempt: null,
        selectedAttemptEvents: [],
        traceExpanded: false,
        eventSource: null,
        creatingSession: false,
        running: false,
        currentStatus: 'idle',
        pendingSessionRefresh: false,
        liveEvents: [],
        liveOutput: '',
        liveReasoning: '',
        activeSeed: null,
        maxLiveEvents: 250,
        form: {
            provider: 'ollama',
            models: [],
            evaluation_model: '',
            benchmarks: [],
            runs_per_benchmark: 1,
            seed_type: 'fixed',
            seed_frequency: 'per_session',
            seed: '',
        },

        async init() {
            this.showBrandCopy = window.localStorage.getItem('benchy.hideBrandCopy') !== '1';

            await Promise.all([
                this.loadConfig(),
                this.refreshSessions(),
                this.refreshLeaderboard(),
            ]);
        },

        async loadConfig() {
            const response = await fetch('/api/config');
            const data = await response.json();
            this.providers = data.providers || [];
            this.benchmarks = data.benchmarks || [];
            this.seedTypes = data.seed_types || [];
            this.seedFrequencies = data.seed_frequencies || [];
            this.form.provider = data.defaults?.provider || 'ollama';
            this.form.runs_per_benchmark = data.defaults?.runs_per_benchmark || 1;
            this.form.seed_type = data.defaults?.seed_type || 'fixed';
            this.form.seed_frequency = data.defaults?.seed_frequency || 'per_session';
            this.form.seed = data.defaults?.seed ?? '';
            this.form.benchmarks = this.benchmarks.slice(0, 4).map((benchmark) => benchmark.id);
            this.normalizeSeedControls();
            await this.loadModels();
        },

        normalizeSeedControls() {
            if (this.form.seed_type === 'fixed') {
                this.form.seed_frequency = 'per_session';
            }
        },

        async loadModels() {
            const response = await fetch('/api/models?provider=' + encodeURIComponent(this.form.provider));
            const data = await response.json();
            this.availableModels = data.models || [];

            const modelIds = this.availableModels.map((model) => model.id);
            this.form.models = this.form.models.filter((modelId) => modelIds.includes(modelId));

            if (!this.form.evaluation_model || !modelIds.includes(this.form.evaluation_model)) {
                this.form.evaluation_model = modelIds[0] || '';
            }
        },

        async refreshSessions() {
            const response = await fetch('/api/sessions');
            const data = await response.json();
            this.sessions = data.sessions || [];
            this.sessionsRefreshedAt = Date.now();
        },

        async refreshSessionsFromUser() {
            await this.refreshSessions();
        },

        async controlSession(action) {
            if (!this.selectedSession) {
                return;
            }

            const response = await fetch('/api/sessions/' + encodeURIComponent(this.selectedSession.id) + '/' + action, {
                method: 'POST',
            });
            const data = await response.json();

            if (!response.ok) {
                window.alert(data.error || 'Failed to update session state.');
                return;
            }

            this.selectedSession = data.session;

            if (action === 'pause') {
                this.currentStatus = 'paused';
            }

            if (action === 'resume') {
                this.currentStatus = 'running';
                this.running = true;
            }

            if (action === 'stop') {
                this.currentStatus = 'stopping';
            }

            await this.refreshSessions();
        },

        canPauseSelectedSession() {
            return this.selectedSession && this.selectedSession.status === 'running';
        },

        canResumeSelectedSession() {
            return this.selectedSession && this.selectedSession.status === 'paused';
        },

        canStopSelectedSession() {
            return this.selectedSession && ['draft', 'running', 'paused', 'evaluating'].includes(this.selectedSession.status);
        },

        async refreshLeaderboard() {
            const response = await fetch('/api/leaderboard');
            const data = await response.json();
            this.leaderboard = data.leaderboard || [];
        },

        async createAndRunSession() {
            this.creatingSession = true;

            try {
                const response = await fetch('/api/sessions', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(this.form),
                });
                const data = await response.json();

                if (!response.ok) {
                    throw new Error(data.error || 'Failed to create session.');
                }

                this.selectedSession = data.session;
                this.selectedAttempt = null;
                this.selectedAttemptEvents = [];
                this.traceExpanded = false;
                this.liveEvents = [];
                this.liveOutput = '';
                this.liveReasoning = '';
                this.activeSeed = null;
                this.running = true;
                this.currentStatus = 'running';
                this.sidebarOpen = false;
                this.dismissBrandCopy();
                await this.refreshSessions();
                this.startStream(this.selectedSession.id);
            } catch (error) {
                const message = error instanceof Error ? error.message : 'Failed to create the session.';
                window.alert(message);
            } finally {
                this.creatingSession = false;
            }
        },

        async selectSession(sessionId) {
            await this.refreshSession(sessionId);
            this.sidebarOpen = false;
        },

        async refreshSession(sessionId) {
            const response = await fetch('/api/sessions/' + encodeURIComponent(sessionId));
            const data = await response.json();
            if (!response.ok) {
                window.alert(data.error || 'Failed to load session.');
                return;
            }

            this.selectedSession = data.session;
            if (this.selectedAttempt) {
                const attempts = Array.isArray(this.selectedSession?.attempts) ? this.selectedSession.attempts : [];
                const currentAttempt = attempts.find((attempt) => attempt.id === this.selectedAttempt.id);
                this.selectedAttempt = currentAttempt || null;
                if (this.selectedAttempt) {
                    this.activeSeed = this.selectedAttempt.effective_seed ?? this.activeSeed;
                }
            }
        },

        selectAttempt(attempt) {
            this.selectedAttempt = attempt;
            this.activeSeed = attempt?.effective_seed ?? this.activeSeed;
            this.selectedAttemptEvents = [];
            this.traceExpanded = false;
            void this.loadAttemptEvents(attempt.id);
        },

        async loadAttemptEvents(attemptId) {
            if (!this.selectedSession) {
                return;
            }

            const response = await fetch('/api/sessions/' + encodeURIComponent(this.selectedSession.id) + '/events?attempt_id=' + encodeURIComponent(attemptId) + '&limit=250');
            const data = await response.json();
            this.selectedAttemptEvents = data.events || [];
        },

        startStream(sessionId) {
            if (this.eventSource) {
                this.eventSource.close();
            }

            this.eventSource = new EventSource('/api/run?session_id=' + encodeURIComponent(sessionId));
            this.eventSource.onmessage = (event) => {
                const payload = JSON.parse(event.data);
                this.handleStreamEvent(payload.event_type || 'message', payload);
            };

            ['attempt_start', 'iteration', 'text_delta', 'reasoning_delta', 'tool_call', 'tool_result', 'attempt_captured', 'attempt_scored', 'attempt_failed', 'evaluation_start', 'session_complete', 'session_failed', 'session_paused', 'session_resumed', 'session_stopped', 'fatal', 'model_start', 'end'].forEach((eventName) => {
                this.eventSource.addEventListener(eventName, (event) => {
                    const payload = JSON.parse(event.data);
                    void this.handleStreamEvent(eventName, payload);
                });
            });
        },

        async handleStreamEvent(eventType, envelope) {
            const payload = envelope.payload || {};

            if (eventType === 'attempt_start') {
                this.liveOutput = '';
                this.liveReasoning = '';
                this.activeSeed = payload.seed ?? null;
                this.currentStatus = this.formatRunStatus(payload);
                await this.refreshSelectedSession(envelope.session_id, envelope.attempt_id, true);
            }

            if (eventType === 'text_delta') {
                this.liveOutput += payload.delta || '';
            }

            if (eventType === 'reasoning_delta') {
                this.liveReasoning += payload.delta || '';
            }

            if (['attempt_captured', 'attempt_scored', 'attempt_failed', 'evaluation_start', 'model_start'].includes(eventType)) {
                await this.refreshSelectedSession(envelope.session_id, envelope.attempt_id, eventType !== 'model_start');
            }

            if (eventType === 'session_paused') {
                this.currentStatus = 'paused';
                await this.refreshSelectedSession(envelope.session_id, envelope.attempt_id, false);
            }

            if (eventType === 'session_resumed') {
                this.currentStatus = 'running';
                this.running = true;
                await this.refreshSelectedSession(envelope.session_id, envelope.attempt_id, false);
            }

            if (eventType === 'session_stopped') {
                this.running = false;
                this.currentStatus = 'stopped';
                if (this.eventSource) {
                    this.eventSource.close();
                }
                await this.refreshSelectedSession(envelope.session_id, envelope.attempt_id, false);
                await this.refreshSessions();
            }

            if (eventType === 'session_complete' || eventType === 'end') {
                this.running = false;
                this.currentStatus = eventType === 'end'
                    ? (payload.status || 'completed')
                    : 'completed';
                if (this.eventSource) {
                    this.eventSource.close();
                }
                await this.refreshSelectedSession(envelope.session_id, envelope.attempt_id, false);
                await this.refreshSessions();
                await this.refreshLeaderboard();
            }

            if (eventType === 'session_failed' || eventType === 'fatal') {
                this.running = false;
                this.currentStatus = 'failed';
                if (this.eventSource) {
                    this.eventSource.close();
                }
            }

            const summary = this.summarizeEvent(eventType, payload);
            this.liveEvents.push({
                key: this.eventKey(),
                event_type: eventType,
                summary,
                payload,
            });

            if (this.liveEvents.length > this.maxLiveEvents) {
                this.liveEvents.splice(0, this.liveEvents.length - this.maxLiveEvents);
            }

            this.scrollConsoleToLatest();
        },

        async refreshSelectedSession(sessionId, attemptId = null, autoSelectAttempt = false) {
            if (!this.selectedSession || this.selectedSession.id !== sessionId || this.pendingSessionRefresh) {
                return;
            }

            this.pendingSessionRefresh = true;

            try {
                await this.refreshSession(sessionId);

                if (autoSelectAttempt && attemptId && Array.isArray(this.selectedSession?.attempts)) {
                    const activeAttempt = this.selectedSession.attempts.find((attempt) => attempt.id === attemptId);
                    if (activeAttempt) {
                        this.selectedAttempt = activeAttempt;
                        this.activeSeed = activeAttempt.effective_seed ?? this.activeSeed;
                    }
                }

                if (this.selectedAttempt && Array.isArray(this.selectedSession?.attempts)) {
                    const updatedAttempt = this.selectedSession.attempts.find((attempt) => attempt.id === this.selectedAttempt.id);
                    if (updatedAttempt) {
                        this.selectedAttempt = updatedAttempt;
                        this.activeSeed = updatedAttempt.effective_seed ?? this.activeSeed;
                    }
                }
            } finally {
                this.pendingSessionRefresh = false;
            }
        },

        scrollConsoleToLatest() {
            requestAnimationFrame(() => {
                const container = document.getElementById('event-stream');
                if (container) {
                    container.scrollTop = container.scrollHeight;
                }
            });
        },

        summarizeEvent(eventType, payload) {
            switch (eventType) {
                case 'attempt_start':
                    return this.formatRunStatus(payload);
                case 'tool_call':
                    return `${payload.name}(${JSON.stringify(payload.arguments)})`;
                case 'tool_result':
                    return `${payload.status}: ${payload.preview || ''}`;
                case 'attempt_scored':
                    return `quality ${payload.quality_score}, total ${payload.total_score}`;
                case 'attempt_captured':
                    return `capability ${payload.capability_score}`;
                case 'session_paused':
                case 'session_resumed':
                case 'session_stopped':
                case 'session_failed':
                case 'fatal':
                    return payload.message || 'Fatal error';
                case 'text_delta':
                case 'reasoning_delta':
                    return (payload.delta || '').slice(0, 120);
                default:
                    return JSON.stringify(payload).slice(0, 160);
            }
        },

        eventKey() {
            return typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
                ? crypto.randomUUID()
                : String(Date.now()) + '-' + Math.random().toString(16).slice(2);
        },

        dismissBrandCopy() {
            this.showBrandCopy = false;
            window.localStorage.setItem('benchy.hideBrandCopy', '1');
        },

        formatRunStatus(payload) {
            return `${payload.model_id} • ${payload.benchmark_id} • run ${payload.run_number} • seed ${this.formatSeed(payload.seed)}`;
        },

        formatBenchmarkLabel(value) {
            return String(value || '')
                .replace(/_/g, ' ')
                .trim();
        },

        formatSeed(value) {
            return value === null || value === undefined || value === '' ? 'auto' : String(value);
        },

        seedPolicyLabel(session) {
            if (!session) {
                return '';
            }

            const type = String(session.seed_type || session.config?.seed_type || 'fixed').replace(/_/g, ' ');
            const frequency = String(session.seed_frequency || session.config?.seed_frequency || 'per_session').replace(/_/g, ' ');

            if (type === 'fixed') {
                return 'fixed • seed ' + this.formatSeed(session.seed);
            }

            return type + ' • ' + frequency + ' • base ' + this.formatSeed(session.seed);
        },

        seedInputHelp() {
            if (this.form.seed_type === 'random') {
                return 'A random base seed will be generated when the session starts.';
            }

            if (this.form.seed_type === 'iterative') {
                return 'Enter the starting seed. Benchy will increment it at the selected frequency.';
            }

            return 'Enter a non-negative seed to make the session reproducible.';
        },

        seedFrequencyHelp() {
            if (this.form.seed_type === 'fixed') {
                return 'Fixed mode always uses one seed for the entire session.';
            }

            if (this.form.seed_frequency === 'per_run') {
                return 'The effective seed changes for every individual run.';
            }

            if (this.form.seed_frequency === 'per_test') {
                return 'The effective seed changes once for each model and benchmark pair.';
            }

            return 'The same effective seed is reused for the full session.';
        },

        formatStatusLabel(value) {
            const normalized = String(value || '').trim();
            if (normalized.length === 0) {
                return 'Unknown';
            }

            return normalized.charAt(0).toUpperCase() + normalized.slice(1);
        },

        formatScore(value, max = 100) {
            const numeric = Number(value || 0);
            return Math.round(numeric) + '/' + max;
        },

        formatJson(value) {
            try {
                return JSON.stringify(value, null, 2);
            } catch (_error) {
                return String(value || '');
            }
        },

        timeAgo(name, _tick) {
            const match = String(name || '').match(/(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2})/);
            if (!match) return name;
            const date = new Date(match[1].replace(' ', 'T'));
            if (isNaN(date.getTime())) return name;
            const secs = Math.floor((Date.now() - date.getTime()) / 1000);
            if (secs < 60) return 'Just now';
            if (secs < 3600) return Math.floor(secs / 60) + ' min ago';
            if (secs < 86400) return Math.floor(secs / 3600) + ' hr ago';
            if (secs < 604800) return Math.floor(secs / 86400) + ' days ago';
            return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
        },
    };
};