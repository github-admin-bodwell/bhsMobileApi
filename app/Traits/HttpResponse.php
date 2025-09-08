<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

Trait HttpResponse {

    protected function successResponse($message = 'Success', $data = [], $code = Response::HTTP_OK): JsonResponse {
        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => $data
        ], $code);
    }

    protected function errorResponse($message = 'Something went wrong', $data = [], $error = null, $code = Response::HTTP_UNPROCESSABLE_ENTITY) : JsonResponse {
        return response()->json([
            'status' => false,
            'message' => $message,
            'data' => $data,
            'error' => $error
        ], $code);
    }

}
