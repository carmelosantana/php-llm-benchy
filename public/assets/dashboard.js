window.dashboardPage = function dashboardPage() {
    return {
        overview: {},
        leaderboard: [],
        recentSessions: [],
        benchmarkComparison: [],
        marioAnalytics: { overview: { total_runs: 0 }, models: [] },
        benchmarkLabels: {},
        charts: {},

        async init() {
            await this.loadDashboard();
        },

        async loadDashboard() {
            const response = await fetch('/api/dashboard');
            const data = await response.json();

            this.overview = data.overview || {};
            this.leaderboard = data.leaderboard || [];
            this.recentSessions = data.recent_sessions || [];
            this.benchmarkComparison = data.benchmark_comparison || [];
            this.marioAnalytics = data.mario_analytics || { overview: { total_runs: 0 }, models: [] };
            this.benchmarkLabels = data.benchmark_labels || {};

            this.$nextTick(() => this.renderCharts());
        },

        overviewCards() {
            return [
                {
                    label: 'Completed Sessions',
                    value: this.overview.completed_sessions || 0,
                    copy: (this.overview.total_sessions || 0) + ' total sessions tracked',
                },
                {
                    label: 'Active Sessions',
                    value: this.overview.active_sessions || 0,
                    copy: (this.overview.failed_sessions || 0) + ' failed session(s)',
                },
                {
                    label: 'Completed Attempts',
                    value: this.overview.completed_attempts || 0,
                    copy: (this.overview.total_attempts || 0) + ' attempts recorded',
                },
                {
                    label: 'Avg Overall Score',
                    value: (this.overview.average_overall_score || 0) + '/100',
                    copy: (this.overview.unique_models || 0) + ' unique model(s)',
                },
            ];
        },

        marioOverviewCards() {
            const overview = this.marioAnalytics?.overview || {};

            return [
                {
                    label: 'Completion Rate',
                    value: (overview.completion_rate || 0) + '%',
                    copy: (overview.completed_runs || 0) + ' of ' + (overview.total_runs || 0) + ' run(s) completed',
                },
                {
                    label: 'Avg Frames',
                    value: overview.average_frames_completed ?? '—',
                    copy: 'Completed runs only',
                },
                {
                    label: 'Avg Deaths',
                    value: overview.average_deaths || 0,
                    copy: 'Across all synthetic Mario attempts',
                },
                {
                    label: 'Avg Checkpoints',
                    value: overview.average_checkpoints_cleared || 0,
                    copy: 'Average checkpoint progress per run',
                },
            ];
        },

        benchmarkLabel(id) {
            return this.benchmarkLabels[id] || String(id || '').replace(/_/g, ' ');
        },

        chartPalette() {
            return ['#8ddf65', '#4fc3f7', '#ffca5f', '#ff7aa2', '#a78bfa', '#f97316', '#22c55e', '#14b8a6'];
        },

        chartTextColor() {
            return '#f4f7f8';
        },

        chartGridColor() {
            return 'rgba(255, 255, 255, 0.12)';
        },

        chartOptions(extra = {}) {
            return {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: {
                            color: this.chartTextColor(),
                        },
                    },
                },
                scales: {
                    x: {
                        ticks: { color: this.chartTextColor() },
                        grid: { color: this.chartGridColor() },
                    },
                    y: {
                        ticks: { color: this.chartTextColor() },
                        grid: { color: this.chartGridColor() },
                    },
                },
                ...extra,
            };
        },

        destroyCharts() {
            Object.values(this.charts).forEach((chart) => {
                if (chart && typeof chart.destroy === 'function') {
                    chart.destroy();
                }
            });
            this.charts = {};
        },

        renderCharts() {
            if (typeof Chart === 'undefined') {
                return;
            }

            this.destroyCharts();
            this.renderOverallLeaderboardChart();
            this.renderRecentSessionsChart();
            this.renderBenchmarkComparisonChart();

            if ((this.marioAnalytics?.overview?.total_runs || 0) > 0) {
                this.renderMarioCompletionChart();
                this.renderMarioFramesChart();
            }
        },

        renderOverallLeaderboardChart() {
            const canvas = document.getElementById('overallLeaderboardChart');
            if (!canvas) {
                return;
            }

            const rows = this.leaderboard.slice(0, 8);
            this.charts.overall = new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: rows.map((row) => row.model_id),
                    datasets: [{
                        label: 'Average Score',
                        data: rows.map((row) => Number(row.average_score || 0)),
                        backgroundColor: this.chartPalette()[0],
                        borderRadius: 8,
                    }],
                },
                options: this.chartOptions({
                    indexAxis: 'y',
                    plugins: {
                        legend: { display: false },
                    },
                }),
            });
        },

        renderRecentSessionsChart() {
            const canvas = document.getElementById('recentSessionsChart');
            if (!canvas) {
                return;
            }

            const rows = [...this.recentSessions].reverse();
            this.charts.recent = new Chart(canvas, {
                type: 'line',
                data: {
                    labels: rows.map((row) => String(row.name || '').replace(/^Session\s+/, '')),
                    datasets: [{
                        label: 'Average Session Score',
                        data: rows.map((row) => Number(row.average_score || 0)),
                        borderColor: this.chartPalette()[1],
                        backgroundColor: 'rgba(79, 195, 247, 0.18)',
                        tension: 0.25,
                        fill: true,
                    }],
                },
                options: this.chartOptions(),
            });
        },

        renderBenchmarkComparisonChart() {
            const canvas = document.getElementById('benchmarkComparisonChart');
            if (!canvas) {
                return;
            }

            const topModels = this.leaderboard.slice(0, 6).map((row) => row.model_id);
            const comparisonRows = this.benchmarkComparison.filter((row) => topModels.includes(row.model_id));
            const benchmarks = [...new Set(comparisonRows.map((row) => row.benchmark_id))];
            const palette = this.chartPalette();
            const datasets = benchmarks.map((benchmarkId, index) => ({
                label: this.benchmarkLabel(benchmarkId),
                data: topModels.map((modelId) => {
                    const row = comparisonRows.find((entry) => entry.model_id === modelId && entry.benchmark_id === benchmarkId);
                    return row ? Number(row.average_score || 0) : null;
                }),
                backgroundColor: palette[index % palette.length],
                borderRadius: 6,
            }));

            this.charts.benchmarkComparison = new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: topModels,
                    datasets,
                },
                options: this.chartOptions(),
            });
        },

        renderMarioCompletionChart() {
            const canvas = document.getElementById('marioCompletionChart');
            if (!canvas) {
                return;
            }

            const rows = this.marioAnalytics.models || [];
            this.charts.marioCompletion = new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: rows.map((row) => row.model_id),
                    datasets: [{
                        label: 'Completion Rate %',
                        data: rows.map((row) => Number(row.completion_rate || 0)),
                        backgroundColor: this.chartPalette()[2],
                        borderRadius: 8,
                    }],
                },
                options: this.chartOptions(),
            });
        },

        renderMarioFramesChart() {
            const canvas = document.getElementById('marioFramesChart');
            if (!canvas) {
                return;
            }

            const rows = (this.marioAnalytics.models || []).filter((row) => row.average_frames_completed !== null);
            this.charts.marioFrames = new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: rows.map((row) => row.model_id),
                    datasets: [{
                        label: 'Average Completed Frames',
                        data: rows.map((row) => Number(row.average_frames_completed || 0)),
                        backgroundColor: this.chartPalette()[3],
                        borderRadius: 8,
                    }],
                },
                options: this.chartOptions(),
            });
        },
    };
};