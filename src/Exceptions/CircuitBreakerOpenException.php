<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\GRPC\Exceptions;

/**
 * Circuit Breaker Open Exception
 * 
 * Thrown when circuit breaker is open and requests are being blocked
 * to prevent cascading failures in the system.
 */
class CircuitBreakerOpenException extends GrpcException
{
    private float $openTime;
    private int $failureCount;
    private float $failureRate;

    public function __construct(
        string $message = 'Circuit breaker is open',
        int $grpcStatus = 14, // UNAVAILABLE
        ?\Throwable $previous = null,
        array $metadata = [],
        float $openTime = 0.0,
        int $failureCount = 0,
        float $failureRate = 0.0
    ) {
        parent::__construct($message, $grpcStatus, $previous, $metadata);
        
        $this->openTime = $openTime ?: microtime(true);
        $this->failureCount = $failureCount;
        $this->failureRate = $failureRate;
    }

    /**
     * Get time when circuit breaker opened
     */
    public function getOpenTime(): float
    {
        return $this->openTime;
    }

    /**
     * Get failure count that triggered circuit breaker
     */
    public function getFailureCount(): int
    {
        return $this->failureCount;
    }

    /**
     * Get failure rate that triggered circuit breaker
     */
    public function getFailureRate(): float
    {
        return $this->failureRate;
    }

    /**
     * Get time since circuit breaker opened
     */
    public function getTimeSinceOpen(): float
    {
        return microtime(true) - $this->openTime;
    }

    /**
     * Get error as array for JSON serialization
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'circuit_breaker' => [
                'open_time' => $this->openTime,
                'failure_count' => $this->failureCount,
                'failure_rate' => $this->failureRate,
                'time_since_open' => $this->getTimeSinceOpen()
            ]
        ]);
    }
}