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
});

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
$autoload = __DIR__.'/../vendor/autoload.php';
if (!file_exists($autoload)) {
    error_log('BOOT ERROR: Missing vendor/autoload.php');
    http_response_code(500);
    echo 'Server error';
    exit(1);
}
require $autoload;

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$bootstrap = __DIR__.'/../bootstrap/app.php';
if (!file_exists($bootstrap)) {
    error_log('BOOT ERROR: Missing bootstrap/app.php');
    http_response_code(500);
    echo 'Server error';
    exit(1);
}
$app = require_once $bootstrap;

$app->handleRequest(Request::capture());
