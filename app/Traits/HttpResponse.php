<?php

namespace App\Traits;

// App/Traits/HttpResponse.php
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

trait HttpResponse
{
    // Recursively sanitize strings (remove ASCII controls except \r \n \t)
    protected function sanitizeForJson(mixed $value): mixed
    {
        if (is_string($value)) {
            // Fix invalid UTF-8 first
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            // Strip control chars that JSON can’t encode cleanly
            return preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        }
        if (is_array($value)) {
            return array_map(fn($v) => $this->sanitizeForJson($v), $value);
        }
        if (is_object($value)) {
            foreach ($value as $k => $v) {
                $value->$k = $this->sanitizeForJson($v);
            }
            return $value;
        }
        return $value;
    }

    protected function successResponse($message = 'Success', $data = [], $code = Response::HTTP_OK): JsonResponse
    {
        $clean = $this->sanitizeForJson($data);

        return response()->json(
            ['status' => true, 'message' => $message, 'data' => $clean],
            $code,
            [],
            JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    protected function errorResponse($message = 'Something went wrong', $data = [], $error = null, $code = Response::HTTP_UNPROCESSABLE_ENTITY): JsonResponse
    {
        $clean = $this->sanitizeForJson($data);

        return response()->json(
            ['status' => false, 'message' => $message, 'data' => $clean, 'error' => $error],
            $code,
            [],
            JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
}
