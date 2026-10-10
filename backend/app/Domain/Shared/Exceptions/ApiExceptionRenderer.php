<?php

namespace App\Domain\Shared\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Converts every exception on /api/* into the standard error envelope. Internal details
 * (SQL, stack traces, class names) are never sent to the client; they are logged with
 * the request id instead.
 */
final class ApiExceptionRenderer
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        [$status, $code, $message, $details] = $this->map($e);

        if ($status >= 500) {
            Log::error($e->getMessage(), ['exception' => $e, 'request_id' => $request->attributes->get('request_id')]);
        }

        $response = response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) $details,
                'request_id' => $request->attributes->get('request_id'),
            ],
        ], $status);

        if ($e instanceof HttpExceptionInterface) {
            $response->withHeaders($e->getHeaders());
        }

        return $response;
    }

    /** @return array{0: int, 1: string, 2: string, 3: array<string, mixed>} */
    private function map(Throwable $e): array
    {
        return match (true) {
            $e instanceof ApiException => [$e->status, $e->errorCode, $e->getMessage(), $e->details],
            $e instanceof ValidationException => [422, 'VALIDATION_FAILED', 'Data yang dikirim tidak valid.', ['fields' => $e->errors()]],
            $e instanceof AuthenticationException => [401, 'UNAUTHENTICATED', 'Silakan login terlebih dahulu.', []],
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => [403, 'FORBIDDEN', 'Anda tidak memiliki izin untuk tindakan ini.', []],
            // Includes records of other tenants: they are indistinguishable from missing ones.
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => [404, 'NOT_FOUND', 'Data tidak ditemukan.', []],
            $e instanceof MethodNotAllowedHttpException => [405, 'METHOD_NOT_ALLOWED', 'Metode tidak didukung.', []],
            $e instanceof ThrottleRequestsException => [429, 'TOO_MANY_REQUESTS', 'Terlalu banyak percobaan. Silakan coba lagi nanti.', []],
            $e instanceof TokenMismatchException,
            $e instanceof HttpExceptionInterface && $e->getStatusCode() === 419 => [419, 'CSRF_TOKEN_MISMATCH', 'Sesi kedaluwarsa. Muat ulang halaman.', []],
            $e instanceof QueryException => $this->mapQuery($e),
            $e instanceof HttpExceptionInterface => [$e->getStatusCode(), 'HTTP_ERROR', 'Permintaan tidak dapat diproses.', []],
            default => [500, 'INTERNAL_ERROR', 'Terjadi kesalahan pada server.', []],
        };
    }

    /** @return array{0: int, 1: string, 2: string, 3: array<string, mixed>} */
    private function mapQuery(QueryException $e): array
    {
        return match ($e->getCode()) {
            '23505' => [409, 'DUPLICATE', 'Data dengan nilai yang sama sudah ada.', []],
            '23503' => [422, 'INVALID_REFERENCE', 'Data referensi tidak valid atau masih digunakan.', []],
            '23514' => [422, 'CONSTRAINT_VIOLATION', 'Data melanggar aturan integritas.', []],
            '42501' => [403, 'FORBIDDEN', 'Anda tidak memiliki izin untuk tindakan ini.', []],
            '40001', '40P01' => [409, 'CONCURRENT_UPDATE', 'Data sedang diubah oleh proses lain. Silakan coba lagi.', []],
            default => [500, 'INTERNAL_ERROR', 'Terjadi kesalahan pada server.', []],
        };
    }
}
