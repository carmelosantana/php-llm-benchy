<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPLLMBenchy;

use CarmeloSantana\PHPLLMBenchy\Benchmark\BenchmarkRegistry;
use CarmeloSantana\PHPLLMBenchy\Config\AppConfig;
use CarmeloSantana\PHPLLMBenchy\Repository\SessionRepository;
use CarmeloSantana\PHPLLMBenchy\Storage\Database;

final class Bootstrap
{
    public static function boot(string $projectRoot): array
    {
        $config = new AppConfig($projectRoot);
        $database = new Database($config);
        $database->migrate();

        return [
            'config' => $config,
            'database' => $database,
            'repository' => new SessionRepository($database->pdo()),
            'benchmarks' => new BenchmarkRegistry(),
        ];
    }
}