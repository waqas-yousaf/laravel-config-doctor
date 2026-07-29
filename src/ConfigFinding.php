<?php

namespace LaravelConfigDoctor;

final readonly class ConfigFinding
{
    public function __construct(
        public string $code,
        public string $severity,
        public string $message,
        public ?string $file = null,
        public ?int $line = null,
        public array $context = [],
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'code' => $this->code,
            'severity' => $this->severity,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'context' => $this->context,
        ], static fn ($value) => $value !== null && $value !== []);
    }
}
