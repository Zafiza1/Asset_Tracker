<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * A caught exception's message, safe to show an API client. Domain errors
     * (validation, wrong state) pass through; database and engine errors are
     * reported and replaced, so SQL, table names and stack details never leak.
     */
    protected function safeMessage(\Throwable $e): string
    {
        if ($e instanceof \Illuminate\Database\QueryException || $e instanceof \PDOException || $e instanceof \Error) {
            report($e);

            return 'The request could not be processed';
        }

        return $e->getMessage();
    }

    /**
     * per_page from the query string, capped at 100 (Section 54).
     */
    protected function perPage(Request $request): int
    {
        return max(1, min((int) $request->query('per_page', 25), 100));
    }

    /**
     * Standard paginated list response (Section 53/54).
     *
     * @param  class-string<JsonResource>  $resource
     */
    protected function paginated(LengthAwarePaginator $paginator, string $resource): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $resource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
