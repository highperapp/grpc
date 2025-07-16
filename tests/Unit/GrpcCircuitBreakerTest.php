<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\GRPC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use HighPerApp\HighPer\GRPC\Reliability\GrpcCircuitBreaker;
use HighPerApp\HighPer\GRPC\Exceptions\CircuitBreakerOpenException;
use HighPerApp\HighPer\GRPC\Exceptions\GrpcException;

/**
 * Unit tests for GrpcCircuitBreaker
 */
class GrpcCircuitBreakerTest extends TestCase
{
    private GrpcCircuitBreaker $circuitBreaker;

    protected function setUp(): void
    {
        $this->circuitBreaker = new GrpcCircuitBreaker([
            'failure_threshold' => 3,
            'success_threshold' => 2,
            'timeout_seconds' => 1,
            'minimum_requests' => 2
        ]);
    }

    public function testSuccessfulOperation(): void
    {
        $result = $this->circuitBreaker->call(function () {
            return 'success';
        });
        
        $this->assertEquals('success', $result);
        $this->assertEquals('closed', $this->circuitBreaker->getState());
    }

    public function testFailedOperation(): void
    {
        $this->expectException(GrpcException::class);
        
        $this->circuitBreaker->call(function () {
            throw new GrpcException('test error');
        });
    }

    public function testCircuitBreakerOpening(): void
    {
        // Generate enough failures to open circuit breaker
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->circuitBreaker->call(function () {
                    throw new GrpcException('test error', 14); // UNAVAILABLE
                });
            } catch (GrpcException $e) {
                // Expected
            }
        }
        
        $this->assertEquals('open', $this->circuitBreaker->getState());
    }

    public function testCircuitBreakerOpenException(): void
    {
        // Force circuit breaker to open
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->circuitBreaker->call(function () {
                    throw new GrpcException('test error', 14); // UNAVAILABLE
                });
            } catch (GrpcException $e) {
                // Expected
            }
        }
        
        $this->expectException(CircuitBreakerOpenException::class);
        
        $this->circuitBreaker->call(function () {
            return 'should not execute';
        });
    }

    public function testCircuitBreakerWithFallback(): void
    {
        // Force circuit breaker to open
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->circuitBreaker->call(function () {
                    throw new GrpcException('test error', 14); // UNAVAILABLE
                });
            } catch (GrpcException $e) {
                // Expected
            }
        }
        
        $result = $this->circuitBreaker->call(
            function () {
                return 'should not execute';
            },
            function () {
                return 'fallback result';
            }
        );
        
        $this->assertEquals('fallback result', $result);
    }

    public function testCircuitBreakerHalfOpen(): void
    {
        // Force circuit breaker to open
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->circuitBreaker->call(function () {
                    throw new GrpcException('test error', 14); // UNAVAILABLE
                });
            } catch (GrpcException $e) {
                // Expected
            }
        }
        
        $this->assertEquals('open', $this->circuitBreaker->getState());
        
        // Wait for timeout
        sleep(2);
        
        // Next call should transition to half-open
        try {
            $this->circuitBreaker->call(function () {
                return 'success';
            });
        } catch (CircuitBreakerOpenException $e) {
            // Check state changed to half-open during the attempt
            $this->assertEquals('half_open', $this->circuitBreaker->getState());
        }
    }

    public function testCircuitBreakerClosing(): void
    {
        // Force circuit breaker to open
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->circuitBreaker->call(function () {
                    throw new GrpcException('test error', 14); // UNAVAILABLE
                });
            } catch (GrpcException $e) {
                // Expected
            }
        }
        
        // Wait for timeout
        sleep(2);
        
        // Simulate successful calls in half-open state
        for ($i = 0; $i < 3; $i++) {
            try {
                $this->circuitBreaker->call(function () {
                    return 'success';
                });
            } catch (CircuitBreakerOpenException $e) {
                // Expected for first call to transition to half-open
            }
        }
        
        $this->assertEquals('closed', $this->circuitBreaker->getState());
    }

    public function testCircuitBreakerStats(): void
    {
        $this->circuitBreaker->call(function () {
            return 'success';
        });
        
        try {
            $this->circuitBreaker->call(function () {
                throw new GrpcException('test error');
            });
        } catch (GrpcException $e) {
            // Expected
        }
        
        $stats = $this->circuitBreaker->getStats();
        
        $this->assertArrayHasKey('total_requests', $stats);
        $this->assertArrayHasKey('total_failures', $stats);
        $this->assertArrayHasKey('total_successes', $stats);
        $this->assertArrayHasKey('failure_rate', $stats);
        $this->assertArrayHasKey('state', $stats);
        
        $this->assertEquals(2, $stats['total_requests']);
        $this->assertEquals(1, $stats['total_failures']);
        $this->assertEquals(1, $stats['total_successes']);
        $this->assertEquals(0.5, $stats['failure_rate']);
    }

    public function testCircuitBreakerReset(): void
    {
        $this->circuitBreaker->call(function () {
            return 'success';
        });
        
        $statsBefore = $this->circuitBreaker->getStats();
        $this->assertGreaterThan(0, $statsBefore['total_requests']);
        
        $this->circuitBreaker->reset();
        
        $statsAfter = $this->circuitBreaker->getStats();
        $this->assertEquals(0, $statsAfter['total_requests']);
        $this->assertEquals('closed', $this->circuitBreaker->getState());
    }

    public function testCircuitBreakerHealthCheck(): void
    {
        $this->assertTrue($this->circuitBreaker->isHealthy());
        
        // Generate failures to make it unhealthy
        for ($i = 0; $i < 10; $i++) {
            try {
                $this->circuitBreaker->call(function () {
                    throw new GrpcException('test error', 14); // UNAVAILABLE
                });
            } catch (GrpcException $e) {
                // Expected
            }
        }
        
        $this->assertFalse($this->circuitBreaker->isHealthy());
    }

    public function testGrpcSpecificFailureTracking(): void
    {
        // Test different gRPC status codes
        $statusCodes = [14, 4, 8, 13]; // UNAVAILABLE, DEADLINE_EXCEEDED, RESOURCE_EXHAUSTED, INTERNAL
        
        foreach ($statusCodes as $status) {
            try {
                $this->circuitBreaker->call(function () use ($status) {
                    throw new GrpcException('test error', $status);
                });
            } catch (GrpcException $e) {
                // Expected
            }
        }
        
        $stats = $this->circuitBreaker->getStats();
        
        $this->assertArrayHasKey('grpc_specific_failures', $stats);
        $this->assertGreaterThan(0, $stats['grpc_specific_failures']['unavailable']);
        $this->assertGreaterThan(0, $stats['grpc_specific_failures']['deadline_exceeded']);
        $this->assertGreaterThan(0, $stats['grpc_specific_failures']['resource_exhausted']);
        $this->assertGreaterThan(0, $stats['grpc_specific_failures']['internal']);
    }
}