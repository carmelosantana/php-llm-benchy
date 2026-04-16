<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPLLMBenchy\Toolkit;

interface BenchmarkTelemetryAwareToolkit
{
    /**
     * @return array<string, mixed>
     */
    public function benchmarkMetrics(): array;
}