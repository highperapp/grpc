<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\GRPC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use HighPerApp\HighPer\GRPC\Reliability\GrpcRetryHandler;
use HighPerApp\HighPer\GRPC\Exceptions\GrpcException;
use Psr\Log\NullLogger;

/**
 * Unit tests for GrpcRetryHandler
 */
class GrpcRetryHandlerTest extends TestCase
{
    private GrpcRetryHandler $retryHandler;

    protected function setUp(): void
    {
        $this->retryHandler = new GrpcRetryHandler([
            'max_attempts' => 3,
            'base_delay_ms' => 10, // Reduced for testing
            'max_delay_ms' => 1000,
            'exponential_base' => 2.0,
            'jitter_factor' => 0.1
        ], new NullLogger());
    }

    public function testSuccessfulOperationNoRetry(): void
    {
        $callCount = 0;
        $result = $this->retryHandler->call(function () use (&$callCount) {
            $callCount++;
            return 'success';
        });

        $this->assertEquals('success', $result);
        $this->assertEquals(1, $callCount);
    }

    public function testRetryOnRetriableError(): void
    {
        $callCount = 0;
        $result = $this->retryHandler->call(function () use (&$callCount) {
            $callCount++;
            if ($callCount < 3) {
                throw new GrpcException('Unavailable', 14); // UNAVAILABLE
            }
            return 'success after retry';
        });

        $this->assertEquals('success after retry', $result);
        $this->assertEquals(3, $callCount);
    }

    public function testNoRetryOnNonRetriableError(): void
    {
        $callCount = 0;
        $this->expectException(GrpcException::class);
        $this->expectExceptionMessage('Invalid argument');

        $this->retryHandler->call(function () use (&$callCount) {
            $callCount++;
            throw new GrpcException('Invalid argument', 3); // INVALID_ARGUMENT
        });

        $this->assertEquals(1, $callCount);
    }

    public function testMaxAttemptsReached(): void
    {
        $callCount = 0;
        $this->expectException(GrpcException::class);
        $this->expectExceptionMessage('gRPC operation failed after 3 attempts');

        $this->retryHandler->call(function () use (&$callCount) {
            $callCount++;
            throw new GrpcException('Always fails', 14); // UNAVAILABLE
        });

        $this->assertEquals(3, $callCount);
    }

    public function testRetryDelayCalculation(): void
    {
        $startTime = microtime(true);
        $callCount = 0;
        
        try {
            $this->retryHandler->call(function () use (&$callCount) {
                $callCount++;
                if ($callCount < 3) {
                    throw new GrpcException('Unavailable', 14); // UNAVAILABLE
                }
                return 'success';
            });
        } catch (GrpcException $e) {
            // Handle any exceptions
        }

        $totalTime = microtime(true) - $startTime;
        
        // Should have some delay between retries
        $this->assertGreaterThan(0.02, $totalTime); // At least 20ms total delay
        $this->assertEquals(3, $callCount);
    }

    public function testRetryHandlerStats(): void
    {
        // Successful call
        $this->retryHandler->call(function () {
            return 'success';
        });

        // Failed call with retry
        try {
            $this->retryHandler->call(function () {
                throw new GrpcException('Always fails', 14); // UNAVAILABLE
            });
        } catch (GrpcException $e) {
            // Expected
        }

        $stats = $this->retryHandler->getStats();

        $this->assertArrayHasKey('total_calls', $stats);
        $this->assertArrayHasKey('total_retries', $stats);
        $this->assertArrayHasKey('successful_retries', $stats);
        $this->assertArrayHasKey('failed_retries', $stats);
        $this->assertArrayHasKey('max_retries_reached', $stats);
        
        $this->assertEquals(2, $stats['total_calls']);
        $this->assertGreaterThan(0, $stats['total_retries']);
    }

    public function testAdaptiveRetryLogic(): void
    {
        $handler = new GrpcRetryHandler([
            'max_attempts' => 3,
            'enable_adaptive_retry' => true,
            'adaptive_success_threshold' => 0.8
        ], new NullLogger());

        // Generate some successful calls to improve success rate
        for ($i = 0; $i < 10; $i++) {
            $handler->call(function () {
                return 'success';
            });
        }

        // Now test adaptive retry behavior
        $callCount = 0;
        try {
            $handler->call(function () use (&$callCount) {
                $callCount++;
                throw new GrpcException('Unknown error', 2); // UNKNOWN
            });
        } catch (GrpcException $e) {
            // Expected
        }

        $stats = $handler->getStats();
        $this->assertGreaterThan(0.5, $stats['success_rate'] ?? 0);
    }

    public function testHealthCheck(): void
    {
        // Initially healthy
        $this->assertTrue($this->retryHandler->isHealthy());

        // Generate many failures
        for ($i = 0; $i < 20; $i++) {
            try {
                $this->retryHandler->call(function () {
                    throw new GrpcException('Always fails', 14); // UNAVAILABLE
                });
            } catch (GrpcException $e) {
                // Expected
            }
        }

        // Should still be healthy due to retry logic
        $this->assertTrue($this->retryHandler->isHealthy());
    }

    public function testResetStats(): void
    {
        $this->retryHandler->call(function () {
            return 'success';
        });

        $statsBefore = $this->retryHandler->getStats();
        $this->assertGreaterThan(0, $statsBefore['total_calls']);

        $this->retryHandler->resetStats();

        $statsAfter = $this->retryHandler->getStats();
        $this->assertEquals(0, $statsAfter['total_calls']);
    }

    public function testRecommendations(): void
    {
        // Generate enough calls to get recommendations
        for ($i = 0; $i < 15; $i++) {
            try {
                $this->retryHandler->call(function () {
                    if (mt_rand(0, 1)) {
                        return 'success';
                    }
                    throw new GrpcException('Random failure', 14); // UNAVAILABLE
                });
            } catch (GrpcException $e) {
                // Expected
            }
        }

        $recommendations = $this->retryHandler->getRecommendations();
        $this->assertIsArray($recommendations);
    }

    public function testGrpcStatusExtraction(): void
    {
        $callCount = 0;
        
        try {
            $this->retryHandler->call(function () use (&$callCount) {
                $callCount++;
                $exception = new \RuntimeException('Internal error');
                throw $exception;
            });
        } catch (GrpcException $e) {
            // Should extract INTERNAL status from RuntimeException
        }

        $this->assertEquals(3, $callCount); // Should retry INTERNAL errors
    }

    public function testTimeoutHandling(): void
    {
        $callCount = 0;
        
        try {
            $this->retryHandler->call(function () use (&$callCount) {
                $callCount++;
                throw new \Exception('Connection timeout');
            });
        } catch (GrpcException $e) {
            // Should treat timeout as retriable
        }

        $this->assertEquals(3, $callCount); // Should retry timeout errors
    }

    public function testJitterInRetryDelay(): void
    {
        $handler = new GrpcRetryHandler([
            'max_attempts' => 2,
            'base_delay_ms' => 100,
            'jitter_factor' => 0.5
        ], new NullLogger());

        $delays = [];
        for ($i = 0; $i < 5; $i++) {
            $startTime = microtime(true);
            try {
                $handler->call(function () {
                    throw new GrpcException('Always fails', 14); // UNAVAILABLE
                });
            } catch (GrpcException $e) {
                // Expected
            }
            $delays[] = microtime(true) - $startTime;
        }

        // Due to jitter, delays should vary
        $this->assertGreaterThan(1, count(array_unique($delays, SORT_NUMERIC)));
    }
}