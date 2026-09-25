<?php

declare(strict_types=1);

namespace Tests\Support;

final class ApiResponse
{
    /** @param array<string,list<string>> $headers */
    public function __construct(
        public readonly string $action,
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers,
    ) {}

    public function json(): array
    {
        $data = json_decode($this->body, true);
        if (!is_array($data)) {
            throw new \UnexpectedValueException("Keine JSON-Antwort für '{$this->action}': " . $this->describe());
        }
        return $data;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    public function describe(): string
    {
        $body = strlen($this->body) > 1500 ? substr($this->body, 0, 1500) . '…' : $this->body;
        return "[{$this->action}] HTTP {$this->status}: {$body}";
    }
}
