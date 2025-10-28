<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ForceJsonForApi
{
    public function handle(Request $request, Closure $next)
    {
        $request->headers->set('Accept', 'application/json');

        /** @var \Symfony\Component\HttpFoundation\Response $response */
        $response = $next($request);

        // Ensure JSON content-type on stringable arrays/objects
        if (method_exists($response, 'headers') && !$response->headers->has('Content-Type')) {
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        }

        if ($request->is('api/*')) {

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
