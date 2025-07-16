<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\GRPC\Reliability;

use HighPerApp\HighPer\GRPC\Exceptions\GrpcException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * gRPC Retry Handler Implementation
 * 
 * Integrates with HighPer framework's retry patterns
 * Specifically designed for gRPC service reliability with exponential backoff
 */
class GrpcRetryHandler
{
    private LoggerInterface $logger;
    private array $config;
    private array $stats = [
        'total_calls' => 0,
        'total_retries' => 0,
        'successful_retries' => 0,
        'failed_retries' => 0,
        'max_retries_reached' => 0,
        'avg_retry_delay' => 0.0,
        'retry_by_status' => [],
        'total_delay_time' => 0.0
    ];

    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->config = array_merge([
            'max_attempts' => 3,
            'base_delay_ms' => 100,
            'max_delay_ms' => 30000, // 30 seconds
            'exponential_base' => 2.0,
            'jitter_factor' => 0.1,
            'timeout_per_attempt' => 30, // seconds
            'retryable_status_codes' => [
                14, // UNAVAILABLE
                4,  // DEADLINE_EXCEEDED
                8,  // RESOURCE_EXHAUSTED
                1,  // CANCELLED (in some cases)
                13, // INTERNAL (depending on configuration)
            ],
            'non_retryable_status_codes' => [
                3,  // INVALID_ARGUMENT
                5,  // NOT_FOUND
                7,  // PERMISSION_DENIED
                9,  // FAILED_PRECONDITION
                10, // ABORTED
                6,  // ALREADY_EXISTS
                11, // OUT_OF_RANGE
                12, // UNIMPLEMENTED
                16, // UNAUTHENTICATED
            ],
            'retry_on_unknown_status' => false,
            'enable_adaptive_retry' => true,
            'adaptive_success_threshold' => 0.8, // 80% success rate
        ], $config);
        
        $this->logger = $logger ?? new NullLogger();
        
        $this->logger->info('gRPC Retry Handler initialized', [
            'config' => $this->config
        ]);
    }

    /**
     * Execute operation with retry logic
     */
    public function call(callable $operation): mixed
    {
        $this->stats['total_calls']++;
        $attempt = 1;
        $totalStartTime = microtime(true);
        
        while ($attempt <= $this->config['max_attempts']) {
            try {
                $attemptStartTime = microtime(true);
                $result = $operation();
                
                // Operation succeeded
                if ($attempt > 1) {
                    $this->stats['successful_retries']++;
                    $this->logger->info('gRPC operation succeeded after retry', [
                        'attempt' => $attempt,
                        'total_attempts' => $this->config['max_attempts'],
                        'total_time' => microtime(true) - $totalStartTime
                    ]);
                }
                
                return $result;
                
            } catch (\Throwable $e) {
                $attemptTime = microtime(true) - $attemptStartTime;
                $grpcStatus = $this->extractGrpcStatus($e);
                
                // Check if we should retry this error
                if (!$this->shouldRetry($e, $attempt, $grpcStatus)) {
                    $this->logger->info('gRPC operation failed - not retrying', [
                        'attempt' => $attempt,
                        'error' => $e->getMessage(),
                        'grpc_status' => $grpcStatus,
                        'reason' => $this->getNoRetryReason($e, $attempt, $grpcStatus)
                    ]);
                    
                    throw $e;
                }
                
                // Track retry statistics
                $this->trackRetryAttempt($grpcStatus, $attemptTime);
                
                // Log the retry attempt
                $this->logger->warning('gRPC operation failed - retrying', [
                    'attempt' => $attempt,
                    'max_attempts' => $this->config['max_attempts'],
                    'error' => $e->getMessage(),
                    'grpc_status' => $grpcStatus,
                    'attempt_time' => $attemptTime
                ]);
                
                // If this was the last attempt, throw the exception
                if ($attempt >= $this->config['max_attempts']) {
                    $this->stats['max_retries_reached']++;
                    $this->logger->error('gRPC operation failed - max retries reached', [
                        'max_attempts' => $this->config['max_attempts'],
                        'final_error' => $e->getMessage(),
                        'grpc_status' => $grpcStatus,
                        'total_time' => microtime(true) - $totalStartTime
                    ]);
                    
                    throw new GrpcException(
                        "gRPC operation failed after {$attempt} attempts: {$e->getMessage()}",
                        0,
                        $e
                    );
                }
                
                // Calculate delay for next attempt
                $delay = $this->calculateRetryDelay($attempt);
                
                // Wait before next attempt
                if ($delay > 0) {
                    $this->logger->debug('gRPC retry delay', [
                        'delay_ms' => $delay,
                        'attempt' => $attempt
                    ]);
                    
                    usleep($delay * 1000); // Convert to microseconds
                }
                
                $attempt++;
            }
        }
        
        // This should never be reached, but just in case
        throw new GrpcException('gRPC retry handler reached unexpected state');
    }

    /**
     * Determine if operation should be retried
     */
    private function shouldRetry(\Throwable $e, int $attempt, int $grpcStatus): bool
    {
        // Check if we have attempts left
        if ($attempt >= $this->config['max_attempts']) {
            return false;
        }
        
        // Check if status code is retryable
        if (in_array($grpcStatus, $this->config['non_retryable_status_codes'])) {
            return false;
        }
        
        if (in_array($grpcStatus, $this->config['retryable_status_codes'])) {
            return true;
        }
        
        // Handle unknown status codes
        if ($grpcStatus === 2) { // UNKNOWN
            return $this->config['retry_on_unknown_status'];
        }
        
        // Adaptive retry logic
        if ($this->config['enable_adaptive_retry']) {
            return $this->shouldAdaptiveRetry($grpcStatus);
        }
        
        return false;
    }

    /**
     * Get reason for not retrying
     */
    private function getNoRetryReason(\Throwable $e, int $attempt, int $grpcStatus): string
    {
        if ($attempt >= $this->config['max_attempts']) {
            return 'max_attempts_reached';
        }
        
        if (in_array($grpcStatus, $this->config['non_retryable_status_codes'])) {
            return 'non_retryable_status_code';
        }
        
        if ($grpcStatus === 2 && !$this->config['retry_on_unknown_status']) {
            return 'unknown_status_not_retryable';
        }
        
        return 'default_no_retry';
    }

    /**
     * Adaptive retry logic based on success rate
     */
    private function shouldAdaptiveRetry(int $grpcStatus): bool
    {
        if ($this->stats['total_calls'] < 10) {
            return true; // Not enough data, allow retry
        }
        
        $successRate = ($this->stats['total_calls'] - $this->stats['failed_retries']) / $this->stats['total_calls'];
        
        // If success rate is above threshold, be more aggressive with retries
        if ($successRate >= $this->config['adaptive_success_threshold']) {
            return true;
        }
        
        // If success rate is low, be more conservative
        return $grpcStatus === 14; // Only retry UNAVAILABLE
    }

    /**
     * Calculate retry delay with exponential backoff and jitter
     */
    private function calculateRetryDelay(int $attempt): int
    {
        // Calculate exponential backoff
        $exponentialDelay = $this->config['base_delay_ms'] * 
            pow($this->config['exponential_base'], $attempt - 1);
        
        // Apply maximum delay limit
        $delay = min($exponentialDelay, $this->config['max_delay_ms']);
        
        // Add jitter to prevent thundering herd
        $jitter = $delay * $this->config['jitter_factor'] * (mt_rand() / mt_getrandmax());
        $delay = $delay + $jitter;
        
        // Update statistics
        $this->updateDelayStats($delay);
        
        return (int) $delay;
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
        
        // Check for specific exception types
        if ($e instanceof \InvalidArgumentException) {
            return 3; // INVALID_ARGUMENT
        }
        
        if ($e instanceof \RuntimeException) {
            return 13; // INTERNAL
        }
        
        if (str_contains($e->getMessage(), 'timeout')) {
            return 4; // DEADLINE_EXCEEDED
        }
        
        if (str_contains($e->getMessage(), 'not found')) {
            return 5; // NOT_FOUND
        }
        
        // Default to unknown
        return 2; // UNKNOWN
    }

    /**
     * Track retry attempt statistics
     */
    private function trackRetryAttempt(int $grpcStatus, float $attemptTime): void
    {
        $this->stats['total_retries']++;
        
        // Track retries by status code
        if (!isset($this->stats['retry_by_status'][$grpcStatus])) {
            $this->stats['retry_by_status'][$grpcStatus] = 0;
        }
        $this->stats['retry_by_status'][$grpcStatus]++;
    }

    /**
     * Update delay statistics
     */
    private function updateDelayStats(float $delay): void
    {
        $this->stats['total_delay_time'] += $delay;
        
        // Update average delay (exponential moving average)
        $alpha = 0.1;
        $this->stats['avg_retry_delay'] = ($alpha * $delay) + 
            ((1 - $alpha) * $this->stats['avg_retry_delay']);
    }

    /**
     * Get retry handler statistics
     */
    public function getStats(): array
    {
        $successRate = $this->stats['total_calls'] > 0 ? 
            (($this->stats['total_calls'] - $this->stats['failed_retries']) / $this->stats['total_calls']) * 100 : 0;
        
        $retryRate = $this->stats['total_calls'] > 0 ? 
            ($this->stats['total_retries'] / $this->stats['total_calls']) * 100 : 0;
        
        return array_merge($this->stats, [
            'success_rate' => $successRate,
            'retry_rate' => $retryRate,
            'avg_attempts_per_call' => $this->stats['total_calls'] > 0 ? 
                ($this->stats['total_calls'] + $this->stats['total_retries']) / $this->stats['total_calls'] : 0,
            'config' => $this->config
        ]);
    }

    /**
     * Reset retry handler statistics
     */
    public function resetStats(): void
    {
        $this->stats = [
            'total_calls' => 0,
            'total_retries' => 0,
            'successful_retries' => 0,
            'failed_retries' => 0,
            'max_retries_reached' => 0,
            'avg_retry_delay' => 0.0,
            'retry_by_status' => [],
            'total_delay_time' => 0.0
        ];
        
        $this->logger->info('gRPC retry handler statistics reset');
    }

    /**
     * Check if retry handler is healthy
     */
    public function isHealthy(): bool
    {
        if ($this->stats['total_calls'] < 10) {
            return true; // Not enough data
        }
        
        $successRate = ($this->stats['total_calls'] - $this->stats['failed_retries']) / $this->stats['total_calls'];
        return $successRate >= 0.5; // At least 50% success rate
    }

    /**
     * Get recommended configuration adjustments
     */
    public function getRecommendations(): array
    {
        $recommendations = [];
        
        if ($this->stats['total_calls'] < 10) {
            return ['message' => 'Not enough data for recommendations'];
        }
        
        $successRate = ($this->stats['total_calls'] - $this->stats['failed_retries']) / $this->stats['total_calls'];
        
        if ($successRate < 0.3) {
            $recommendations[] = 'Consider increasing max_attempts or base_delay_ms';
        }
        
        if ($this->stats['max_retries_reached'] > $this->stats['total_calls'] * 0.1) {
            $recommendations[] = 'Consider increasing max_attempts';
        }
        
        if ($this->stats['avg_retry_delay'] > 1000) {
            $recommendations[] = 'Consider reducing base_delay_ms or max_delay_ms';
        }
        
        return $recommendations;
    }
}