<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\GRPC\Reliability;

use HighPerApp\HighPer\GRPC\Exceptions\GrpcException;
use HighPerApp\HighPer\GRPC\Exceptions\CircuitBreakerOpenException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * gRPC Circuit Breaker Implementation
 * 
 * Integrates with HighPer framework's circuit breaker patterns
 * Specifically designed for gRPC service reliability
 */
class GrpcCircuitBreaker
{
    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';
    
    private string $state = self::STATE_CLOSED;
    private int $failureCount = 0;
    private int $successCount = 0;
    private int $requestCount = 0;
    private float $lastFailureTime = 0;
    private float $lastSuccessTime = 0;
    private LoggerInterface $logger;
    private array $config;
    private array $stats = [
        'total_requests' => 0,
        'total_failures' => 0,
        'total_successes' => 0,
        'total_fallbacks' => 0,
        'state_changes' => 0,
        'last_state_change' => 0,
        'avg_response_time' => 0.0,
        'failure_rate' => 0.0,
        'grpc_specific_failures' => [
            'unavailable' => 0,
            'deadline_exceeded' => 0,
            'resource_exhausted' => 0,
            'internal' => 0,
            'unknown' => 0
        ]
    ];

    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->config = array_merge([
            'failure_threshold' => 5,
            'success_threshold' => 3,
            'timeout_seconds' => 60,
            'half_open_max_requests' => 3,
            'slow_call_threshold' => 1.0, // 1 second
            'slow_call_rate_threshold' => 0.5, // 50%
            'minimum_requests' => 10,
            'sliding_window_size' => 100,
            'grpc_specific_failures' => [
                'unavailable' => true,
                'deadline_exceeded' => true,
                'resource_exhausted' => true,
                'internal' => false, // Don't count internal errors
                'unknown' => false
            ]
        ], $config);
        
        $this->logger = $logger ?? new NullLogger();
        
        $this->logger->info('gRPC Circuit Breaker initialized', [
            'config' => $this->config
        ]);
    }

    /**
     * Execute operation with circuit breaker protection
     */
    public function call(callable $operation, ?callable $fallback = null): mixed
    {
        $this->stats['total_requests']++;
        $startTime = microtime(true);
        
        // Check if circuit is open
        if ($this->isOpen()) {
            if ($fallback !== null) {
                return $this->executeFallback($fallback, $startTime);
            }
            throw new CircuitBreakerOpenException("gRPC circuit breaker is open");
        }
        
        // Check if we're in half-open state and have reached max requests
        if ($this->isHalfOpen() && $this->requestCount >= $this->config['half_open_max_requests']) {
            if ($fallback !== null) {
                return $this->executeFallback($fallback, $startTime);
            }
            throw new CircuitBreakerOpenException("gRPC circuit breaker half-open limit reached");
        }
        
        try {
            $this->requestCount++;
            $result = $operation();
            $this->recordSuccess($startTime);
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->recordFailure($e, $startTime);
            
            if ($fallback !== null) {
                return $this->executeFallback($fallback, $startTime);
            }
            
            throw $e;
        }
    }

    /**
     * Record successful operation
     */
    private function recordSuccess(float $startTime): void
    {
        $responseTime = microtime(true) - $startTime;
        $this->updateAverageResponseTime($responseTime);
        
        $this->successCount++;
        $this->stats['total_successes']++;
        $this->lastSuccessTime = microtime(true);
        
        // Check for slow calls
        if ($responseTime > $this->config['slow_call_threshold']) {
            $this->logger->warning('Slow gRPC call detected', [
                'response_time' => $responseTime,
                'threshold' => $this->config['slow_call_threshold']
            ]);
        }
        
        // Transition from half-open to closed if we have enough successes
        if ($this->isHalfOpen() && $this->successCount >= $this->config['success_threshold']) {
            $this->transitionToClosed();
        }
    }

    /**
     * Record failed operation
     */
    private function recordFailure(\Throwable $e, float $startTime): void
    {
        $responseTime = microtime(true) - $startTime;
        $this->updateAverageResponseTime($responseTime);
        
        $this->failureCount++;
        $this->stats['total_failures']++;
        $this->lastFailureTime = microtime(true);
        
        // Track gRPC-specific failures
        $this->trackGrpcSpecificFailure($e);
        
        // Update failure rate
        $this->updateFailureRate();
        
        $this->logger->warning('gRPC operation failed', [
            'error' => $e->getMessage(),
            'failure_count' => $this->failureCount,
            'response_time' => $responseTime,
            'grpc_status' => $this->extractGrpcStatus($e)
        ]);
        
        // Check if we should transition to open state
        if ($this->shouldTransitionToOpen()) {
            $this->transitionToOpen();
        }
    }

    /**
     * Execute fallback operation
     */
    private function executeFallback(callable $fallback, float $startTime): mixed
    {
        $this->stats['total_fallbacks']++;
        
        try {
            $result = $fallback();
            $responseTime = microtime(true) - $startTime;
            
            $this->logger->info('gRPC fallback executed successfully', [
                'response_time' => $responseTime
            ]);
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->logger->error('gRPC fallback failed', [
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * Check if circuit breaker is open
     */
    private function isOpen(): bool
    {
        if ($this->state === self::STATE_OPEN) {
            // Check if timeout has passed
            if (microtime(true) - $this->lastFailureTime >= $this->config['timeout_seconds']) {
                $this->transitionToHalfOpen();
                return false;
            }
            return true;
        }
        return false;
    }

    /**
     * Check if circuit breaker is half-open
     */
    private function isHalfOpen(): bool
    {
        return $this->state === self::STATE_HALF_OPEN;
    }

    /**
     * Check if circuit breaker should transition to open
     */
    private function shouldTransitionToOpen(): bool
    {
        // Need minimum number of requests
        if ($this->stats['total_requests'] < $this->config['minimum_requests']) {
            return false;
        }
        
        // Check failure threshold
        if ($this->failureCount >= $this->config['failure_threshold']) {
            return true;
        }
        
        // Check failure rate
        if ($this->stats['failure_rate'] > 0.5) { // 50% failure rate
            return true;
        }
        
        return false;
    }

    /**
     * Transition to open state
     */
    private function transitionToOpen(): void
    {
        $this->state = self::STATE_OPEN;
        $this->requestCount = 0;
        $this->stats['state_changes']++;
        $this->stats['last_state_change'] = microtime(true);
        
        $this->logger->warning('gRPC circuit breaker opened', [
            'failure_count' => $this->failureCount,
            'failure_rate' => $this->stats['failure_rate'],
            'total_requests' => $this->stats['total_requests']
        ]);
    }

    /**
     * Transition to half-open state
     */
    private function transitionToHalfOpen(): void
    {
        $this->state = self::STATE_HALF_OPEN;
        $this->requestCount = 0;
        $this->successCount = 0;
        $this->stats['state_changes']++;
        $this->stats['last_state_change'] = microtime(true);
        
        $this->logger->info('gRPC circuit breaker half-opened', [
            'timeout_seconds' => $this->config['timeout_seconds']
        ]);
    }

    /**
     * Transition to closed state
     */
    private function transitionToClosed(): void
    {
        $this->state = self::STATE_CLOSED;
        $this->failureCount = 0;
        $this->successCount = 0;
        $this->requestCount = 0;
        $this->stats['state_changes']++;
        $this->stats['last_state_change'] = microtime(true);
        
        $this->logger->info('gRPC circuit breaker closed', [
            'success_count' => $this->successCount
        ]);
    }

    /**
     * Track gRPC-specific failure types
     */
    private function trackGrpcSpecificFailure(\Throwable $e): void
    {
        $grpcStatus = $this->extractGrpcStatus($e);
        
        switch ($grpcStatus) {
            case 14: // UNAVAILABLE
                $this->stats['grpc_specific_failures']['unavailable']++;
                break;
            case 4: // DEADLINE_EXCEEDED
                $this->stats['grpc_specific_failures']['deadline_exceeded']++;
                break;
            case 8: // RESOURCE_EXHAUSTED
                $this->stats['grpc_specific_failures']['resource_exhausted']++;
                break;
            case 13: // INTERNAL
                $this->stats['grpc_specific_failures']['internal']++;
                break;
            default:
                $this->stats['grpc_specific_failures']['unknown']++;
        }
    }

    /**
     * Extract gRPC status code from exception
     */
    private function extractGrpcStatus(\Throwable $e): int
    {
        // Check if it's a gRPC exception with status code
        if ($e instanceof GrpcException && method_exists($e, 'getGrpcStatus')) {
            return $e->getGrpcStatus();
        }
        
        // Try to extract from message
        if (preg_match('/grpc-status:\s*(\d+)/', $e->getMessage(), $matches)) {
            return (int) $matches[1];
        }
        
        // Default to unknown
        return 2; // UNKNOWN
    }

    /**
     * Update failure rate
     */
    private function updateFailureRate(): void
    {
        if ($this->stats['total_requests'] > 0) {
            $this->stats['failure_rate'] = $this->stats['total_failures'] / $this->stats['total_requests'];
        }
    }

    /**
     * Update average response time
     */
    private function updateAverageResponseTime(float $responseTime): void
    {
        $alpha = 0.1; // Exponential moving average factor
        $this->stats['avg_response_time'] = ($alpha * $responseTime) + 
            ((1 - $alpha) * $this->stats['avg_response_time']);
    }

    /**
     * Get current state
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * Get circuit breaker statistics
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'state' => $this->state,
            'failure_count' => $this->failureCount,
            'success_count' => $this->successCount,
            'request_count' => $this->requestCount,
            'last_failure_time' => $this->lastFailureTime,
            'last_success_time' => $this->lastSuccessTime,
            'is_open' => $this->isOpen(),
            'is_half_open' => $this->isHalfOpen(),
            'config' => $this->config
        ]);
    }

    /**
     * Reset circuit breaker
     */
    public function reset(): void
    {
        $this->state = self::STATE_CLOSED;
        $this->failureCount = 0;
        $this->successCount = 0;
        $this->requestCount = 0;
        $this->lastFailureTime = 0;
        $this->lastSuccessTime = 0;
        
        // Reset stats
        $this->stats = array_merge($this->stats, [
            'total_requests' => 0,
            'total_failures' => 0,
            'total_successes' => 0,
            'total_fallbacks' => 0,
            'failure_rate' => 0.0,
            'grpc_specific_failures' => [
                'unavailable' => 0,
                'deadline_exceeded' => 0,
                'resource_exhausted' => 0,
                'internal' => 0,
                'unknown' => 0
            ]
        ]);
        
        $this->logger->info('gRPC circuit breaker reset');
    }

    /**
     * Check if circuit breaker is healthy
     */
    public function isHealthy(): bool
    {
        return $this->state === self::STATE_CLOSED && 
               $this->stats['failure_rate'] < 0.1; // Less than 10% failure rate
    }
}