<?php

namespace App\Http\Controllers\Api;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Collection;

class Controller extends BaseController
{
    protected function ok($data = null, string $message = 'OK', array $extra = []): JsonResponse
    {
        if ($data instanceof LengthAwarePaginator) {
            $extra['pagination'] = [
                'current_page' => $data->currentPage(),
                'last_page' => $data->lastPage(),
                'per_page' => $data->perPage(),
                'total' => $data->total(),
                'from' => $data->firstItem(),
                'to' => $data->lastItem(),
            ];
            $data = $data->items();
        }

        if ($data instanceof EloquentCollection || $data instanceof Collection) {
            $data = $data->values();
        }

        return response()->json(array_merge([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $extra));
    }

    protected function fail(string $message, int $status = 422, array $errors = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }

    /**
     * Backward-compatible error response helper used by older API controllers.
     */
    protected function error(string $message, int $status = 422, array $errors = []): JsonResponse
    {
        return $this->fail($message, $status, $errors);
    }
}
