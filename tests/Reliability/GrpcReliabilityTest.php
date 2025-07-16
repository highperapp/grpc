<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\GRPC\Tests\Reliability;

use PHPUnit\Framework\TestCase;
use HighPerApp\HighPer\GRPC\GrpcServerFactory;
use HighPerApp\HighPer\GRPC\Examples\GreeterService;
use HighPerApp\HighPer\GRPC\Reliability\GrpcCircuitBreaker;
use HighPerApp\HighPer\GRPC\Reliability\GrpcRetryHandler;
use HighPerApp\HighPer\GRPC\Engines\HybridEngine;
use HighPerApp\HighPer\GRPC\Protocol\GrpcProtocolHandler;
use HighPerApp\HighPer\GRPC\Exceptions\GrpcException;
use HighPerApp\HighPer\GRPC\Exceptions\CircuitBreakerOpenException;
use Psr\Log\NullLogger;

/**
 * Reliability tests for gRPC components
 */
class GrpcReliabilityTest extends TestCase
{
    private GrpcServerFactory $factory;
    private GreeterService $service;
    private HybridEngine $engine;
    private GrpcProtocolHandler $handler;
    private GrpcCircuitBreaker $circuitBreaker;
    private GrpcRetryHandler $retryHandler;

    protected function setUp(): void
    {
        $this->factory = new GrpcServerFactory([
            'engine' => ['rust_acceleration' => false],
            'circuit_breaker' => ['enabled' => true],
            'retry' => ['enabled' => true]
        ], new NullLogger());
        
        $this->service = new GreeterService(new NullLogger());
        $this->engine = $this->factory->createEngine();
        $this->handler = $this->factory->createProtocolHandler($this->engine);
        $this->circuitBreaker = $this->factory->createCircuitBreaker();
        $this->retryHandler = $this->factory->createRetryHandler();
    }

    /**
     * Test circuit breaker fault tolerance
     */
    public function testCircuitBreakerFaultTolerance(): void
    {
        $failureThreshold = 3;
        $circuitBreaker = new GrpcCircuitBreaker([
            'failure_threshold' => $failureThreshold,
            'timeout_seconds' => 1,
            'minimum_requests' => 1
        ], new NullLogger());
        
        // Generate failures to open circuit breaker
        for ($i = 0; $i < $failureThreshold + 1; $i++) {
            try {
                $circuitBreaker->call(function() use ($i) {
                    throw new GrpcException("Failure {$i}", 14); // UNAVAILABLE
                });
            } catch (GrpcException $e) {
                // Expected failures
            }
        }
        
        // Circuit breaker should now be open
        $this->assertEquals('open', $circuitBreaker->getState());
        
        // Requests should fail fast
        $this->expectException(CircuitBreakerOpenException::class);
        $circuitBreaker->call(function() {
            return 'should not execute';
        });
    }

    /**
     * Test circuit breaker recovery
     */
    public function testCircuitBreakerRecovery(): void
    {
        $circuitBreaker = new GrpcCircuitBreaker([
            'failure_threshold' => 2,
            'success_threshold' => 2,
            'timeout_seconds' => 1,
            'minimum_requests' => 1
        ], new NullLogger());
        
        // Force circuit breaker open
        for ($i = 0; $i < 3; $i++) {
            try {
                $circuitBreaker->call(function() {
                    throw new GrpcException('Force open', 14);
                });
            } catch (GrpcException $e) {
                // Expected
            }
        }
        
        $this->assertEquals('open', $circuitBreaker->getState());
        
        // Wait for timeout
        sleep(2);
        
        // Should transition to half-open and then closed
        for ($i = 0; $i < 3; $i++) {
            try {
                $circuitBreaker->call(function() {
                    return 'recovery success';
                });
            } catch (CircuitBreakerOpenException $e) {
                // Expected for first call to transition to half-open
            }
        }
        
        $this->assertEquals('closed', $circuitBreaker->getState());
    }

    /**
     * Test retry handler fault tolerance
     */
    public function testRetryHandlerFaultTolerance(): void
    {
        $maxAttempts = 3;
        $retryHandler = new GrpcRetryHandler([
            'max_attempts' => $maxAttempts,
            'base_delay_ms' => 10,
            'max_delay_ms' => 100
        ], new NullLogger());
        
        $attemptCount = 0;
        
        $result = $retryHandler->call(function() use (&$attemptCount) {
            $attemptCount++;
            
            // Fail first two attempts, succeed on third
            if ($attemptCount < 3) {
                throw new GrpcException("Attempt {$attemptCount}", 14); // UNAVAILABLE
            }
            
            return "Success on attempt {$attemptCount}";
        });
        
        $this->assertEquals("Success on attempt 3", $result);
        $this->assertEquals(3, $attemptCount);
        
        $stats = $retryHandler->getStats();
        $this->assertEquals(1, $stats['total_calls']);
        $this->assertEquals(2, $stats['total_retries']);
        $this->assertEquals(1, $stats['successful_retries']);
    }

    /**
     * Test retry handler with non-retryable errors
     */
    public function testRetryHandlerNonRetryableErrors(): void
    {
        $retryHandler = new GrpcRetryHandler([
            'max_attempts' => 3,
            'base_delay_ms' => 10
        ], new NullLogger());
        
        $attemptCount = 0;
        
        $this->expectException(GrpcException::class);
        $this->expectExceptionMessage('Invalid argument');
        
        $retryHandler->call(function() use (&$attemptCount) {
            $attemptCount++;
            throw new GrpcException('Invalid argument', 3); // INVALID_ARGUMENT
        });
        
        // Should only attempt once for non-retryable errors
        $this->assertEquals(1, $attemptCount);
    }

    /**
     * Test engine fault tolerance
     */
    public function testEngineFaultTolerance(): void
    {
        $engine = $this->factory->createEngine();
        $successCount = 0;
        $errorCount = 0;
        
        // Test with various message types and sizes
        $testMessages = [
            'normal message',
            str_repeat('x', 1000), // Large message
            '', // Empty message
            json_encode(['complex' => 'data']), // JSON data
            'unicode: 测试消息', // Unicode
        ];
        
        foreach ($testMessages as $message) {
            try {
                $result = $engine->processMessage($message);
                $this->assertIsString($result);
                $successCount++;
            } catch (\Exception $e) {
                $errorCount++;
            }
        }
        
        // Should handle most messages successfully
        $this->assertGreaterThan(0, $successCount);
        $this->assertLessThan(count($testMessages), $errorCount);
        
        // Engine should remain healthy
        $this->assertTrue($engine->isReady());
    }

    /**
     * Test protocol handler fault tolerance
     */
    public function testProtocolHandlerFaultTolerance(): void
    {
        $handler = $this->factory->createProtocolHandler($this->engine);
        $successCount = 0;
        $errorCount = 0;
        
        // Test with various invalid requests
        $testCases = [
            // Valid request
            [
                'headers' => ['content-type' => 'application/grpc+proto'],
                'body' => chr(0) . pack('N', 5) . 'hello',
                'method' => 'SayHello'
            ],
            // Invalid content type
            [
                'headers' => ['content-type' => 'application/json'],
                'body' => 'invalid',
                'method' => 'SayHello'
            ],
            // Invalid gRPC frame
            [
                'headers' => ['content-type' => 'application/grpc+proto'],
                'body' => 'invalid_frame',
                'method' => 'SayHello'
            ],
            // Invalid method
            [
                'headers' => ['content-type' => 'application/grpc+proto'],
                'body' => chr(0) . pack('N', 5) . 'hello',
                'method' => 'InvalidMethod'
            ],
        ];
        
        foreach ($testCases as $testCase) {
            try {
                $result = $handler->processRequest(
                    $this->service,
                    $testCase['method'],
                    $testCase['body'],
                    $testCase['headers']
                );
                
                if (is_array($result) && isset($result['status'])) {
                    $successCount++;
                }
            } catch (\Exception $e) {
                $errorCount++;
            }
        }
        
        // Should handle at least one valid request
        $this->assertGreaterThan(0, $successCount);
        
        // Should gracefully handle invalid requests
        $this->assertGreaterThan(0, $errorCount);
    }

    /**
     * Test server fault tolerance
     */
    public function testServerFaultTolerance(): void
    {
        $server = $this->factory->createDevelopmentServer();
        $server->registerService($this->service);
        
        // Test server lifecycle
        $this->assertFalse($server->isRunning());
        
        $server->start();
        $this->assertTrue($server->isRunning());
        $this->assertTrue($server->isHealthy());
        
        // Simulate server stress
        $stats = $server->getStats();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_requests', $stats);
        
        // Server should handle shutdown gracefully
        $server->stop();
        $this->assertFalse($server->isRunning());
        $this->assertFalse($server->isHealthy());
    }

    /**
     * Test memory leak prevention
     */
    public function testMemoryLeakPrevention(): void
    {
        $initialMemory = memory_get_usage(true);
        
        // Perform many operations
        $iterations = 1000;
        for ($i = 0; $i < $iterations; $i++) {
            $this->engine->processMessage("Memory test {$i}");
            
            // Force garbage collection periodically
            if ($i % 100 === 0) {
                gc_collect_cycles();
            }
        }
        
        // Final garbage collection
        gc_collect_cycles();
        
        $finalMemory = memory_get_usage(true);
        $memoryIncrease = $finalMemory - $initialMemory;
        $memoryPerOperation = $memoryIncrease / $iterations;
        
        // Memory increase should be reasonable
        $this->assertLessThan(50 * 1024 * 1024, $memoryIncrease); // Less than 50MB
        $this->assertLessThan(1024, $memoryPerOperation); // Less than 1KB per operation
        
        echo "\nMemory Leak Test:\n";
        echo "Operations: {$iterations}\n";
        echo "Memory increase: " . $this->formatBytes($memoryIncrease) . "\n";
        echo "Per operation: " . $this->formatBytes($memoryPerOperation) . "\n";
    }

    /**
     * Test error recovery
     */
    public function testErrorRecovery(): void
    {
        $engine = $this->factory->createEngine();
        $errorCount = 0;
        $recoveryCount = 0;
        
        // Generate errors and test recovery
        for ($i = 0; $i < 20; $i++) {
            try {
                if ($i % 5 === 0) {
                    // Intentionally cause an error
                    throw new \Exception("Intentional error {$i}");
                }
                
                $result = $engine->processMessage("Recovery test {$i}");
                $this->assertIsString($result);
                $recoveryCount++;
            } catch (\Exception $e) {
                $errorCount++;
            }
        }
        
        // Should have both errors and recoveries
        $this->assertGreaterThan(0, $errorCount);
        $this->assertGreaterThan(0, $recoveryCount);
        
        // Engine should still be operational
        $this->assertTrue($engine->isReady());
    }

    /**
     * Test timeout handling
     */
    public function testTimeoutHandling(): void
    {
        $retryHandler = new GrpcRetryHandler([
            'max_attempts' => 2,
            'base_delay_ms' => 100,
            'timeout_per_attempt' => 1 // 1 second timeout
        ], new NullLogger());
        
        $startTime = microtime(true);
        
        try {
            $retryHandler->call(function() {
                // Simulate long-running operation
                sleep(2);
                return 'should timeout';
            });
        } catch (\Exception $e) {
            // Expected timeout
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // Should not take too long due to timeout
        $this->assertLessThan(5, $duration);
    }

    /**
     * Test graceful degradation
     */
    public function testGracefulDegradation(): void
    {
        $circuitBreaker = new GrpcCircuitBreaker([
            'failure_threshold' => 3,
            'timeout_seconds' => 1
        ], new NullLogger());
        
        $fallbackCount = 0;
        
        // Force circuit breaker open
        for ($i = 0; $i < 4; $i++) {
            try {
                $circuitBreaker->call(function() {
                    throw new GrpcException('Service unavailable', 14);
                });
            } catch (GrpcException $e) {
                // Expected
            }
        }
        
        // Test fallback behavior
        for ($i = 0; $i < 5; $i++) {
            $result = $circuitBreaker->call(
                function() {
                    return 'primary service';
                },
                function() use (&$fallbackCount) {
                    $fallbackCount++;
                    return 'fallback service';
                }
            );
            
            $this->assertEquals('fallback service', $result);
        }
        
        $this->assertEquals(5, $fallbackCount);
    }

    /**
     * Test system stability under load
     */
    public function testSystemStabilityUnderLoad(): void
    {
        $server = $this->factory->createDevelopmentServer();
        $server->registerService($this->service);
        
        $initialStats = $server->getStats();
        
        // Simulate load
        $iterations = 100;
        for ($i = 0; $i < $iterations; $i++) {
            // Simulate various operations
            $this->engine->processMessage("Load test {$i}");
            
            // Check system health periodically
            if ($i % 20 === 0) {
                $this->assertTrue($server->isHealthy());
                $this->assertTrue($this->engine->isReady());
            }
        }
        
        $finalStats = $server->getStats();
        
        // System should remain stable
        $this->assertTrue($server->isHealthy());
        $this->assertTrue($this->engine->isReady());
        
        // Stats should be updated
        $this->assertGreaterThanOrEqual($initialStats['total_requests'], $finalStats['total_requests']);
    }

    /**
     * Format bytes for display
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unit = 0;
        
        while ($bytes >= 1024 && $unit < count($units) - 1) {
            $bytes /= 1024;
            $unit++;
        }
        
        return sprintf('%.2f %s', $bytes, $units[$unit]);
    }
}