<?php

declare(strict_types=1);

namespace FileVault\Observability;

final readonly class ErrorEnvelope
{
    /**
     * @param array<string, mixed>|null $details
     */
    public function __construct(
        public string $code,
        public string $message,
        public string $requestId,
        public ?array $details = null
    ) {}

    /**
     * @return array{error: array{code: string, message: string, request_id: string, details: array<string, mixed>|null}}
     */
    public function toArray(): array
    {
        return [
            'error' => [
                'code' => $this->code,
                'message' => $this->message,
                'request_id' => $this->requestId,
                'details' => $this->details,
            ],
        ];
    }

    public function toJson(): string
    {
        $encoded = json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            // Absolute fallback to avoid recursion
            return sprintf(
                '{"error":{"code":"internal_error","message":"An internal error occurred.","request_id":%s,"details":null}}',
                json_encode($this->requestId)
            );
        }

        return $encoded;
    }
}
