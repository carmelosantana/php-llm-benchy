window.sessionDetailPage = function sessionDetailPage(sessionId) {
    return {
        sessionId,
        session: null,
        selectedAttempt: null,
        selectedAttemptEvents: [],
        traceExpanded: false,

        async init() {
            await this.refreshSession();
        },

        async refreshSession() {
            const response = await fetch('/api/sessions/' + encodeURIComponent(this.sessionId));
            const data = await response.json();

            if (!response.ok) {
                window.alert(data.error || 'Failed to load session.');
                return;
            }

            this.session = data.session;

            if (!Array.isArray(this.session?.attempts) || this.session.attempts.length === 0) {
                this.selectedAttempt = null;
                this.selectedAttemptEvents = [];
                return;
            }

            const currentAttemptId = this.selectedAttempt?.id || null;
            const updatedAttempt = currentAttemptId
                ? this.session.attempts.find((attempt) => attempt.id === currentAttemptId)
                : this.session.attempts[0];

            this.selectedAttempt = updatedAttempt || this.session.attempts[0];

            if (this.selectedAttempt) {
                await this.loadAttemptEvents(this.selectedAttempt.id);
            }
        },

        async selectAttempt(attempt) {
            this.selectedAttempt = attempt;
            this.selectedAttemptEvents = [];
            this.traceExpanded = false;
            await this.loadAttemptEvents(attempt.id);
        },

        async loadAttemptEvents(attemptId) {
            const response = await fetch('/api/sessions/' + encodeURIComponent(this.sessionId) + '/events?attempt_id=' + encodeURIComponent(attemptId) + '&limit=250');
            const data = await response.json();
            this.selectedAttemptEvents = data.events || [];
        },

        sessionOverviewCards() {
            if (!this.session) {
                return [];
            }

            const attempts = this.session.attempts || [];
            const modelScores = this.session.model_scores || [];
            const benchmarkScores = this.session.benchmark_scores || [];
            const topModel = modelScores[0] || null;
            const averageBenchmarkScore = benchmarkScores.length > 0
                ? Math.round((benchmarkScores.reduce((sum, row) => sum + Number(row.average_score || 0), 0) / benchmarkScores.length) * 100) / 100
                : 0;

            return [
                {
                    label: 'Attempts',
                    value: attempts.length,
                    copy: attempts.filter((attempt) => attempt.status === 'completed').length + ' completed',
                },
                {
                    label: 'Models',
                    value: (this.session.models || []).length,
                    copy: (this.session.benchmarks || []).length + ' benchmark(s)',
                },
                {
                    label: 'Top Model',
                    value: topModel ? topModel.model_id : '—',
                    copy: topModel ? (topModel.overall_score + '/100 overall') : 'No scored models yet',
                },
                {
                    label: 'Avg Benchmark Score',
                    value: averageBenchmarkScore,
                    copy: 'Average across model benchmark summaries',
                },
            ];
        },

        isMarioBenchmark(attempt) {
            return (attempt?.benchmark_id || '') === 'mario_speedrun_synthetic';
        },

        marioSummary(attempt) {
            return attempt?.metrics?.synthetic_mario || null;
        },

        marioSummaryCards(attempt) {
            const summary = this.marioSummary(attempt) || {};

            return [
                {
                    label: 'Outcome',
                    value: summary.completed ? 'Completed' : 'Failed',
                    copy: summary.completed ? 'Goal tape reached in the synthetic course' : 'Run did not reach the goal',
                },
                {
                    label: 'Frames',
                    value: summary.frames_used ?? '—',
                    copy: 'Target ' + (summary.target_frames ?? '—') + ' • Max ' + (summary.max_frames ?? '—'),
                },
                {
                    label: 'Deaths',
                    value: summary.deaths ?? 0,
                    copy: 'Synthetic death count for this run',
                },
                {
                    label: 'Invalid Actions',
                    value: summary.invalid_actions ?? 0,
                    copy: 'Out-of-contract button or wait sequences',
                },
            ];
        },

        marioCheckpointPercent(attempt) {
            const summary = this.marioSummary(attempt) || {};
            const count = Number(summary.checkpoint_count || 0);
            const cleared = Number(summary.checkpoints_cleared || 0);

            if (count <= 0) {
                return 0;
            }

            return Math.round((cleared / count) * 100);
        },

        marioCheckpointLabel(attempt) {
            const summary = this.marioSummary(attempt) || {};
            return (summary.checkpoints_cleared || 0) + '/' + (summary.checkpoint_count || 0) + ' checkpoints';
        },

        marioFailureCopy(attempt) {
            const summary = this.marioSummary(attempt) || {};
            if (summary.completed) {
                return 'Completed the synthetic course and reached the goal tape.';
            }

            return summary.failure_reason ? ('Failure reason: ' + String(summary.failure_reason).replace(/_/g, ' ')) : 'Run ended without a recorded failure reason.';
        },

        genericSummaryCards(attempt) {
            const metrics = attempt?.metrics || {};
            const usage = attempt?.usage || {};
            const toolNames = Array.isArray(metrics.tool_names) ? metrics.tool_names : [];

            return [
                {
                    label: 'Total Score',
                    value: this.formatScore(attempt?.total_score || 0),
                    copy: 'Capability + rubric score',
                },
                {
                    label: 'Iterations',
                    value: metrics.iterations ?? '—',
                    copy: 'Agent loop count',
                },
                {
                    label: 'Tools Used',
                    value: toolNames.length,
                    copy: toolNames.length > 0 ? toolNames.join(', ') : 'No tools recorded',
                },
                {
                    label: 'Tokens',
                    value: usage.total_tokens ?? '—',
                    copy: 'Prompt ' + (usage.prompt_tokens ?? '—') + ' • Completion ' + (usage.completion_tokens ?? '—'),
                },
            ];
        },

        formatBenchmarkLabel(value) {
            return String(value || '').replace(/_/g, ' ').trim();
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
    };
};