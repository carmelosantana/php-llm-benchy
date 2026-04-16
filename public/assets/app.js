window.benchyApp = function benchyApp() {
    return {
        sidebarOpen: false,
        showBrandCopy: true,
        providers: [],
        benchmarks: [],
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
        maxLiveEvents: 250,
        form: {
            provider: 'ollama',
            models: [],
            evaluation_model: '',
            benchmarks: [],
            runs_per_benchmark: 1,
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
            this.form.provider = data.defaults?.provider || 'ollama';
            this.form.runs_per_benchmark = data.defaults?.runs_per_benchmark || 1;
            this.form.seed = data.defaults?.seed ?? '';
            this.form.benchmarks = this.benchmarks.slice(0, 4).map((benchmark) => benchmark.id);
            await this.loadModels();
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
        },

        async refreshSessionsFromUser() {
            this.dismissBrandCopy();
            await this.refreshSessions();
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
            }
        },

        selectAttempt(attempt) {
            this.selectedAttempt = attempt;
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

            ['attempt_start', 'iteration', 'text_delta', 'reasoning_delta', 'tool_call', 'tool_result', 'attempt_captured', 'attempt_scored', 'attempt_failed', 'evaluation_start', 'session_complete', 'session_failed', 'fatal', 'model_start', 'end'].forEach((eventName) => {
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
                this.currentStatus = payload.model_id + ' • ' + payload.benchmark_id + ' • run ' + payload.run_number;
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

            if (eventType === 'session_complete' || eventType === 'end') {
                this.running = false;
                this.currentStatus = 'completed';
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
                    }
                }

                if (this.selectedAttempt && Array.isArray(this.selectedSession?.attempts)) {
                    const updatedAttempt = this.selectedSession.attempts.find((attempt) => attempt.id === this.selectedAttempt.id);
                    if (updatedAttempt) {
                        this.selectedAttempt = updatedAttempt;
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
                    return `${payload.model_id} • ${payload.benchmark_id} • run ${payload.run_number}`;
                case 'tool_call':
                    return `${payload.name}(${JSON.stringify(payload.arguments)})`;
                case 'tool_result':
                    return `${payload.status}: ${payload.preview || ''}`;
                case 'attempt_scored':
                    return `quality ${payload.quality_score}, total ${payload.total_score}`;
                case 'attempt_captured':
                    return `capability ${payload.capability_score}`;
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

        formatJson(value) {
            try {
                return JSON.stringify(value, null, 2);
            } catch (_error) {
                return String(value || '');
            }
        },
    };
};