<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Log fatal errors that occur before Laravel boots.
register_shutdown_function(function (): void {
    $err = error_get_last();
    if (!$err) {
        return;
    }

    if (!in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    $uri = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
    $path = $_SERVER['REQUEST_URI'] ?? '';
    $msg = sprintf(
        'BOOT FATAL: %s in %s:%s (%s %s)',
        $err['message'] ?? 'Unknown fatal error',
        $err['file'] ?? 'unknown',
        $err['line'] ?? '0',
        $uri,
        $path
    );

    error_log($msg);

    $tempDir = sys_get_temp_dir();
    if (is_string($tempDir) && $tempDir !== '') {
        $tempLog = rtrim($tempDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'laravel-boot.log';
        @file_put_contents($tempLog, $msg . PHP_EOL, FILE_APPEND);
    }
});

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
$autoload = __DIR__.'/../vendor/autoload.php';
if (!file_exists($autoload)) {
    $msg = 'BOOT ERROR: Missing vendor/autoload.php';
    error_log($msg);
    $tempDir = sys_get_temp_dir();
    if (is_string($tempDir) && $tempDir !== '') {
        $tempLog = rtrim($tempDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'laravel-boot.log';
        @file_put_contents($tempLog, $msg . PHP_EOL, FILE_APPEND);
    }
    http_response_code(500);
    echo 'Server error';
    exit(1);
}
require $autoload;

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$bootstrap = __DIR__.'/../bootstrap/app.php';
if (!file_exists($bootstrap)) {
    $msg = 'BOOT ERROR: Missing bootstrap/app.php';
    error_log($msg);
    $tempDir = sys_get_temp_dir();
    if (is_string($tempDir) && $tempDir !== '') {
        $tempLog = rtrim($tempDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'laravel-boot.log';
        @file_put_contents($tempLog, $msg . PHP_EOL, FILE_APPEND);
    }
    http_response_code(500);
    echo 'Server error';
    exit(1);
}
$app = require_once $bootstrap;

$app->handleRequest(Request::capture());
