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

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Support\Str;

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

        $middleware->append(ForceJsonForApi::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Helper: detect API request
        $isApi = fn ($request) => $request->is('api/*') || $request->expectsJson();
        
        $exceptions->shouldRenderJsonWhen(function ($request) {
            return $request->is('api/*')
                || Str::startsWith($request->getHost(), 'api.');
        });

        // 401 Unauthenticated (no redirects to login page)
        $exceptions->render(function (AuthenticationException $e, $request) use ($isApi) {
            if ($isApi($request)) {
                report($e);
                return response()->json([
                    'status'  => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });

        // 422 Validation errors (no HTML)
        $exceptions->render(function (ValidationException $e, $request) use ($isApi) {
            if ($isApi($request)) {
                report($e);
                return response()->json([
                    'status'  => false,
                    'message' => 'Validation failed.',
                    'errors'  => $e->errors(),
                ], 422);
            }
        });

        // 403 Forbidden (authorization failure)
        $exceptions->render(function (AuthorizationException $e, $request) use ($isApi) {
            if ($isApi($request)) {
                report($e);
                return response()->json([
                    'status'  => false,
                    'message' => $e->getMessage() ?: 'Forbidden.',
                ], 403);
            }
        });

        // 404 via Eloquent (ModelNotFoundException)
        $exceptions->render(function (ModelNotFoundException $e, $request) use ($isApi) {
            if ($isApi($request)) {
                report($e);
                return response()->json([
                    'status'  => false,
                    'message' => 'Not Found - ',
                ], 404);
            }
        });

        // 422 Validation errors
        $exceptions->render(function (ValidationException $e, $request) use ($isApi) {
            if ($isApi($request)) {
                report($e);
                return response()->json([
                    'status'  => false,
                    'message' => 'Validation failed.',
                    'errors'  => $e->errors(),
                ], 422);
            }
        });

        // 405 Method not allowed
        $exceptions->render(function (MethodNotAllowedHttpException $e, $request) use ($isApi) {
            if ($isApi($request)) {
                report($e);
                return response()->json([
                    'status'  => false,
                    'message' => 'Method Not Allowed',
                ], 405);
            }
        });

        // 419 CSRF token mismatch
        $exceptions->render(function (TokenMismatchException $e, $request) use ($isApi) {
            if ($isApi($request)) {
                report($e);
                return response()->json([
                    'status'  => false,
                    'message' => 'Page Expired',
                ], 419);
            }
        });

        // 429 Too Many Requests (rate limiting)
        $exceptions->render(function (ThrottleRequestsException $e, $request) use ($isApi) {
            if ($isApi($request)) {
                report($e);
                return response()->json([
                    'status'  => false,
                    'message' => 'Too Many Requests',
                ], 429);
            }
        });

        // Known HTTP exceptions (403/404/429/etc.) fallback
        $exceptions->render(function (HttpExceptionInterface $e, $request) use ($isApi) {
            if ($isApi($request)) {
                report($e);
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
                report($e);
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
            ->withoutOverlapping();

        // Refresh Long-Lived Token from Facebook
        $schedule->command('fb:refresh-token')
                 ->weekly();

        // Sync Instagram feeds to database
        $schedule->command('community:sync-instagram --limit=150 --page-size=25')
            ->hourly()
            ->withoutOverlapping();
    })
    ->create();
