<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A domain rule was violated (wrong state, conflict, not allowed). Renders in
 * the standard API error format (Section 53).
 */
class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        protected int $status = 409,
        protected array $errors = [],
    ) {
        parent::__construct($message);
    }

    public static function conflict(string $message): static
    {
        return new static($message, 409);
    }

    public static function invalid(string $message, array $errors = []): static
    {
        return new static($message, 422, $errors);
    }

    public static function notFound(string $message): static
    {
        return new static($message, 404);
    }

    public static function forbidden(string $message): static
    {
        return new static($message, 403);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function render(): JsonResponse
    {
        $body = [
            'success' => false,
            'message' => $this->getMessage(),
        ];

        if ($this->errors) {
            $body['errors'] = $this->errors;
        }

        return response()->json($body, $this->status);
    }
}
