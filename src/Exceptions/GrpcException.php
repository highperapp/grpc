<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\GRPC\Exceptions;

/**
 * Base gRPC Exception
 * 
 * Provides gRPC-specific error handling with status codes
 * and detailed error information following gRPC error model.
 */
class GrpcException extends \Exception
{
    private int $grpcStatus;
    private array $metadata;
    private ?string $details;

    public function __construct(
        string $message = '',
        int $grpcStatus = 13, // INTERNAL by default
        ?\Throwable $previous = null,
        array $metadata = [],
        ?string $details = null
    ) {
        parent::__construct($message, $grpcStatus, $previous);
        
        $this->grpcStatus = $grpcStatus;
        $this->metadata = $metadata;
        $this->details = $details;
    }

    /**
     * Get gRPC status code
     */
    public function getGrpcStatus(): int
    {
        return $this->grpcStatus;
    }

    /**
     * Get gRPC status name
     */
    public function getGrpcStatusName(): string
    {
        return match ($this->grpcStatus) {
            0 => 'OK',
            1 => 'CANCELLED',
            2 => 'UNKNOWN',
            3 => 'INVALID_ARGUMENT',
            4 => 'DEADLINE_EXCEEDED',
            5 => 'NOT_FOUND',
            6 => 'ALREADY_EXISTS',
            7 => 'PERMISSION_DENIED',
            8 => 'RESOURCE_EXHAUSTED',
            9 => 'FAILED_PRECONDITION',
            10 => 'ABORTED',
            11 => 'OUT_OF_RANGE',
            12 => 'UNIMPLEMENTED',
            13 => 'INTERNAL',
            14 => 'UNAVAILABLE',
            15 => 'DATA_LOSS',
            16 => 'UNAUTHENTICATED',
            default => 'UNKNOWN'
        };
    }

    /**
     * Get error metadata
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Get error details
     */
    public function getDetails(): ?string
    {
        return $this->details;
    }

    /**
     * Set error metadata
     */
    public function setMetadata(array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    /**
     * Set error details
     */
    public function setDetails(string $details): self
    {
        $this->details = $details;
        return $this;
    }

    /**
     * Create gRPC exception from status code
     */
    public static function fromStatus(int $status, string $message = '', array $metadata = []): self
    {
        return new self($message, $status, null, $metadata);
    }

    /**
     * Create INVALID_ARGUMENT exception
     */
    public static function invalidArgument(string $message = '', array $metadata = []): self
    {
        return new self($message, 3, null, $metadata);
    }

    /**
     * Create NOT_FOUND exception
     */
    public static function notFound(string $message = '', array $metadata = []): self
    {
        return new self($message, 5, null, $metadata);
    }

    /**
     * Create PERMISSION_DENIED exception
     */
    public static function permissionDenied(string $message = '', array $metadata = []): self
    {
        return new self($message, 7, null, $metadata);
    }

    /**
     * Create UNAVAILABLE exception
     */
    public static function unavailable(string $message = '', array $metadata = []): self
    {
        return new self($message, 14, null, $metadata);
    }

    /**
     * Create INTERNAL exception
     */
    public static function internal(string $message = '', array $metadata = []): self
    {
        return new self($message, 13, null, $metadata);
    }

    /**
     * Create DEADLINE_EXCEEDED exception
     */
    public static function deadlineExceeded(string $message = '', array $metadata = []): self
    {
        return new self($message, 4, null, $metadata);
    }

    /**
     * Create RESOURCE_EXHAUSTED exception
     */
    public static function resourceExhausted(string $message = '', array $metadata = []): self
    {
        return new self($message, 8, null, $metadata);
    }

    /**
     * Create UNIMPLEMENTED exception
     */
    public static function unimplemented(string $message = '', array $metadata = []): self
    {
        return new self($message, 12, null, $metadata);
    }

    /**
     * Create UNAUTHENTICATED exception
     */
    public static function unauthenticated(string $message = '', array $metadata = []): self
    {
        return new self($message, 16, null, $metadata);
    }

    /**
     * Check if error is retriable
     */
    public function isRetriable(): bool
    {
        return in_array($this->grpcStatus, [
            1,  // CANCELLED
            4,  // DEADLINE_EXCEEDED
            8,  // RESOURCE_EXHAUSTED
            13, // INTERNAL
            14, // UNAVAILABLE
        ]);
    }

    /**
     * Get error as array for JSON serialization
     */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'grpc_status' => $this->grpcStatus,
            'grpc_status_name' => $this->getGrpcStatusName(),
            'metadata' => $this->metadata,
            'details' => $this->details,
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'trace' => $this->getTraceAsString()
        ];
    }

    /**
     * Convert to string representation
     */
    public function __toString(): string
    {
        $status = $this->getGrpcStatusName();
        $message = $this->getMessage();
        
        return "gRPC {$status} ({$this->grpcStatus}): {$message}";
    }
}