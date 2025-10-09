<?php

use App\Http\Middleware\ForceJsonForApi;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'as.json' => ForceJsonForApi::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Helper: detect API request
        $isApi = fn ($request) => $request->is('api/*') || $request->expectsJson();

        // 401 Unauthenticated (no redirects to login page)
        $exceptions->render(function (AuthenticationException $e, $request) use ($isApi) {
            if ($isApi($request)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });

        // 422 Validation errors (no HTML)
        $exceptions->render(function (ValidationException $e, $request) use ($isApi) {
            if ($isApi($request)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Validation failed.',
                    'errors'  => $e->errors(),
                ], 422);
            }
        });

        // Known HTTP exceptions: 403/404/419/429/etc.
        $exceptions->render(function (HttpExceptionInterface $e, $request) use ($isApi) {
            if ($isApi($request)) {
                $status  = $e->getStatusCode();
                $default = Response::$statusTexts[$status] ?? 'Error';
                return response()->json([
                    'status'  => false,
                    'message' => $e->getMessage() ?: $default,
                ], $status);
            }
        });

        // Catch-all for anything else -> JSON 500
        $exceptions->render(function (Throwable $e, $request) use ($isApi) {
            if ($isApi($request)) {
                // report($e); // uncomment if you want to log
                return response()->json([
                    'status'  => false,
                    'message' => config('app.debug') ? ($e->getMessage() ?: 'Server error') : 'Server error',
                ], 500);
            }
        });
    })->withSchedule(function (Schedule $schedule) {
        // Sync Calendar from FinalSite
        $schedule->command('calendar:sync')
            ->hourly()
            ->withoutOverlapping()
            ->onOneServer();

        // Refresh Long-Lived Token from Facebook
        $schedule->command('fb:refresh-token')
                 ->weekly();

        // Sync Instagram feeds to database
        $schedule->command('community:sync-instagram --limit=150 --page-size=25')
            ->hourly()
            ->withoutOverlapping()
            ->onOneServer();
    })
    ->create();
