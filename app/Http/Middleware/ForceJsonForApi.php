<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

class ForceJsonForApi
{
    public function handle(Request $request, Closure $next)
    {
        $request->headers->set('Accept', 'application/json');

        /** @var \Symfony\Component\HttpFoundation\Response $response */
        $response = $next($request);

        $isApi = $request->is('api/*') || $request->expectsJson();
        if (!$isApi) {
            return $response;
        }

        // Avoid forcing JSON on redirects or non-JSON responses.
        if ($response instanceof RedirectResponse) {
            return $response;
        }

        if ($response instanceof JsonResponse) {
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
            $content = (string) $response->getContent();
            if ($content !== '') {
                $content = preg_replace('/^\xEF\xBB\xBF/', '', $content); // BOM
                $content = ltrim($content); // leading whitespace/newlines
                $response->setContent($content);
            }
        }

        return $response;
    }
}
