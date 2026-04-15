<?php

declare(strict_types=1);

use CarmeloSantana\PHPLLMBenchy\Bootstrap;
use CarmeloSantana\PHPLLMBenchy\Http\App;
use CarmeloSantana\PHPLLMBenchy\Runner\BenchmarkRunner;
use CarmeloSantana\PHPLLMBenchy\Runner\ModelProviderFactory;

if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_file($file)) {
        return false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

$services = Bootstrap::boot(dirname(__DIR__));
$providerFactory = new ModelProviderFactory($services['config']);
$runner = new BenchmarkRunner(
    $services['config'],
    $services['repository'],
    $services['benchmarks'],
    $providerFactory,
);

$app = new App(
    $services['config'],
    $services['repository'],
    $services['benchmarks'],
    $providerFactory,
    $runner,
);

$app->handle();