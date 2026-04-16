# php-llm-benchy

php-llm-benchy is a local Ollama-first benchmark workbench for comparing LLMs across tool use, memory, shell execution, PHP code generation, creative writing, and synthetic game-control tasks. It stores full run history in SQLite, streams live attempt traces to the browser, and produces definitive scores out of 100 for every attempt, benchmark, and model.

## What it does

- Creates benchmark sessions with a provider, one or more candidate models, one evaluation model, a benchmark selection, run count, and seed.
- Discovers models dynamically from the provider model API. v1 is wired for Ollama at `http://ollama:11434/v1`.
- Runs models sequentially, captures raw response text, reasoning deltas, and tool activity, then evaluates quality in a second pass.
- Persists sessions, attempts, stream events, benchmark averages, model rollups, and CSV export data in SQLite.
- Shows previous sessions in a sidebar, current-session model rankings in a side rail, and overall leaders across completed sessions.

## Scoring model

Each attempt is scored on a strict 100-point scale.

- Capability score: 0-50
- Quality rubric score: 0-50

The quality rubric uses five criteria scored from 0 to 10 by the evaluation model.

- Relevance
- Coherence
- Creativity
- Accuracy
- Fluency

Benchmark scores are the average of their attempts. Model scores are the average of that model's benchmark scores in the session.

## Benchmarks

V1 ships with eight benchmarks.

- Tool use
- Concurrent tool use
- Synthetic Mario speedrun
- Memory recall
- Restricted shell execution
- PHP script quality
- Creative story quality
- Poem quality

The Mario benchmark is synthetic. It reuses php-plays-style state and control semantics, but it does not launch a real emulator or ROM.

## Requirements

- PHP 8.4+
- Composer
- SQLite support enabled in PHP
- An Ollama-compatible endpoint available at `http://ollama:11434/v1`

## Setup

1. Install dependencies.

```bash
composer install
```

1. Copy the environment template and adjust values if needed.

```bash
cp .env.example .env
```

1. Confirm the Ollama endpoint and models you want to benchmark are available.

Example:

```bash
curl http://ollama:11434/v1/models
```

## Run locally

Start the built-in PHP server:

```bash
./bin/serve
```

Then open `http://127.0.0.1:8080`.

## Test and analysis

Run the test suite:

```bash
composer test
```

Run static analysis:

```bash
composer analyse
```

## Notes on runtime behavior

- Model runs are sequential by design.
- Evaluation happens after raw capture so the evaluation model can stay hot.
- Full trace persistence is enabled, including tool calls, tool results, streamed text deltas, and reasoning deltas when the model emits them.
- The shell benchmark is restricted to an allowlist and runs only inside the configured sandbox directory.
- The current server setup uses PHP's built-in server. It is fine for local development, but it is not intended as a production deployment target.

## Project layout

- `public/index.php`: entrypoint and HTTP router
- `public/assets/`: UI styles and browser logic
- `src/Runner/BenchmarkRunner.php`: session execution orchestration
- `src/Evaluation/ResponseEvaluator.php`: capability and rubric scoring
- `src/Repository/SessionRepository.php`: SQLite persistence API
- `src/Benchmark/BenchmarkRegistry.php`: benchmark catalog

## Current scope

This version focuses on the local single-user workflow.

- No authentication
- No multi-provider UI beyond Ollama yet
- No background job system
- No production packaging
