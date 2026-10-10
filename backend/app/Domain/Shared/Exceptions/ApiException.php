<?php

namespace App\Domain\Shared\Exceptions;

use RuntimeException;

/**
 * Business-rule failure rendered as the standard error envelope:
 * { "error": { "code", "message", "details", "request_id" } }.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string, mixed> $details */
    public static function conflict(string $code, string $message, array $details = []): self
    {
        return new self($code, $message, 409, $details);
    }

    /** @param array<string, mixed> $details */
    public static function unprocessable(string $code, string $message, array $details = []): self
    {
        return new self($code, $message, 422, $details);
    }

    public static function forbidden(string $code = 'FORBIDDEN', string $message = 'Anda tidak memiliki izin untuk tindakan ini.'): self
    {
        return new self($code, $message, 403);
    }
}
