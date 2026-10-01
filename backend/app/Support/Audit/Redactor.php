<?php

namespace App\Support\Audit;

/**
 * Masks sensitive values before they are written to any audit record or
 * structured log line (Section 55: never log passwords or secrets).
 */
class Redactor
{
    public const MASK = '[REDACTED]';

    /** @param list<string> $sensitiveKeys */
    public function __construct(protected array $sensitiveKeys)
    {
        $this->sensitiveKeys = array_map('strtolower', $sensitiveKeys);
    }

    public static function fromConfig(): self
    {
        return new self(config('audit.redact_keys', []));
    }

    /**
     * Recursively replaces the value of every sensitive key.
     */
    public function redact(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $data[$key] = self::MASK;
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }

    public function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach ($this->sensitiveKeys as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
