<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\GRPC\Tests\Concurrency;

use PHPUnit\Framework\TestCase;
use HighPerApp\HighPer\GRPC\GrpcServerFactory;
use HighPerApp\HighPer\GRPC\Examples\GreeterService;
use HighPerApp\HighPer\GRPC\Engines\HybridEngine;
use HighPerApp\HighPer\GRPC\Protocol\GrpcProtocolHandler;
use HighPerApp\HighPer\GRPC\Parallel\GrpcProcessingTask;
use HighPerApp\HighPer\GRPC\Reliability\GrpcCircuitBreaker;
use HighPerApp\HighPer\GRPC\Reliability\GrpcRetryHandler;
use Psr\Log\NullLogger;

/**
 * Concurrency tests for gRPC components
 */
class GrpcConcurrencyTest extends TestCase
{
    private GrpcServerFactory $factory;
    private GreeterService $service;
    private HybridEngine $engine;
    private GrpcProtocolHandler $handler;

    protected function setUp(): void
    {
        $this->factory = new GrpcServerFactory([
            'engine' => ['rust_acceleration' => false], // Use PHP for consistent testing
            'circuit_breaker' => ['enabled' => false],
            'retry' => ['enabled' => false]
        ], new NullLogger());
        
        $this->service = new GreeterService(new NullLogger());
        $this->engine = $this->factory->createEngine();
        $this->handler = $this->factory->createProtocolHandler($this->engine);
    }

    /**
     * Test concurrent message processing
     */
    public function testConcurrentMessageProcessing(): void
    {
        $concurrentRequests = 50;
        $tasks = [];
        
        // Create concurrent tasks
        for ($i = 0; $i < $concurrentRequests; $i++) {
            $task = new GrpcProcessingTask(
                GreeterService::class,
                'SayHello',
                json_encode(['name' => "User{$i}"]),
                ['content-type' => 'application/grpc+proto']
            );
            $tasks[] = $task;
        }
        
        // Simulate concurrent processing
        $startTime = microtime(true);
        $results = [];
        
        foreach ($tasks as $task) {
            // In real concurrency, this would be executed in parallel
            // For testing, we simulate the concurrent load
            $metadata = $task->getMetadata();
            $this->assertIsArray($metadata);
            $this->assertArrayHasKey('service_class', $metadata);
            $results[] = $metadata;
        }
        
        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        
        // Verify all tasks were processed
        $this->assertCount($concurrentRequests, $results);
        $this->assertLessThan(5.0, $totalTime); // Should complete within 5 seconds
        
        // Verify task uniqueness
        $uniqueIds = array_unique(array_map(function($task) {
            return $task->getId();
        }, $tasks));
        $this->assertCount($concurrentRequests, $uniqueIds);
    }

    /**
     * Test concurrent engine operations
     */
    public function testConcurrentEngineOperations(): void
    {
        $operations = 100;
        $messages = [];
        
        // Prepare messages
        for ($i = 0; $i < $operations; $i++) {
            $messages[] = "Message {$i}";
        }
        
        $startTime = microtime(true);
        $results = [];
        
        // Process messages concurrently (simulated)
        foreach ($messages as $message) {
            $result = $this->engine->processMessage($message);
            $results[] = $result;
        }
        
        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        
        // Verify all operations completed
        $this->assertCount($operations, $results);
        $this->assertLessThan(2.0, $totalTime); // Should complete within 2 seconds
        
        // Verify engine stats
        $stats = $this->engine->getStats();
        $this->assertGreaterThanOrEqual($operations, $stats['operations_total']);
    }

    /**
     * Test concurrent protocol handler requests
     */
    public function testConcurrentProtocolHandlerRequests(): void
    {
        $concurrentRequests = 30;
        $headers = [
            'content-type' => 'application/grpc+proto',
            'user-agent' => 'concurrency-test/1.0'
        ];
        
        $startTime = microtime(true);
        $results = [];
        
        // Process concurrent requests
        for ($i = 0; $i < $concurrentRequests; $i++) {
            $message = json_encode(['name' => "Concurrent{$i}"]);
            $grpcFrame = chr(0) . pack('N', strlen($message)) . $message;
            
            $result = $this->handler->processRequest(
                $this->service,
                'SayHello',
                $grpcFrame,
                $headers
            );
            
            $results[] = $result;
        }
        
        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        
        // Verify all requests processed successfully
        $this->assertCount($concurrentRequests, $results);
        
        foreach ($results as $result) {
            $this->assertIsArray($result);
            $this->assertEquals(0, $result['status']);
            $this->assertArrayHasKey('headers', $result);
            $this->assertArrayHasKey('body', $result);
        }
        
        // Performance check
        $avgTime = $totalTime / $concurrentRequests;
        $this->assertLessThan(0.1, $avgTime); // Less than 100ms per request
    }

    /**
     * Test circuit breaker under concurrent load
     */
    public function testCircuitBreakerUnderConcurrentLoad(): void
    {
        $circuitBreaker = new GrpcCircuitBreaker([
            'failure_threshold' => 5,
            'timeout_seconds' => 1,
            'minimum_requests' => 3
        ], new NullLogger());
        
        $concurrentRequests = 20;
        $failureCount = 0;
        $successCount = 0;
        
        // Simulate concurrent requests with some failures
        for ($i = 0; $i < $concurrentRequests; $i++) {
            try {
                $result = $circuitBreaker->call(function() use ($i) {
                    // Simulate some failures
                    if ($i % 3 === 0) {
                        throw new \Exception("Simulated failure {$i}");
                    }
                    return "Success {$i}";
                });
                
                $successCount++;
            } catch (\Exception $e) {
                $failureCount++;
            }
        }
        
        // Verify circuit breaker handled concurrent load
        $this->assertGreaterThan(0, $successCount);
        $this->assertGreaterThan(0, $failureCount);
        
        $stats = $circuitBreaker->getStats();
        $this->assertEquals($concurrentRequests, $stats['total_requests']);
        $this->assertEquals($failureCount, $stats['total_failures']);
        $this->assertEquals($successCount, $stats['total_successes']);
    }

    /**
     * Test retry handler under concurrent load
     */
    public function testRetryHandlerUnderConcurrentLoad(): void
    {
        $retryHandler = new GrpcRetryHandler([
            'max_attempts' => 3,
            'base_delay_ms' => 1, // Very short for testing
            'max_delay_ms' => 100
        ], new NullLogger());
        
        $concurrentRequests = 15;
        $results = [];
        
        // Simulate concurrent requests with retries
        for ($i = 0; $i < $concurrentRequests; $i++) {
            try {
                $result = $retryHandler->call(function() use ($i) {
                    // Simulate intermittent failures
                    if ($i % 4 === 0 && rand(0, 1)) {
                        throw new \HighPerApp\HighPer\GRPC\Exceptions\GrpcException(
                            "Intermittent failure {$i}",
                            14 // UNAVAILABLE
                        );
                    }
                    return "Success {$i}";
                });
                
                $results[] = $result;
            } catch (\Exception $e) {
                // Some failures are expected
                $results[] = 'failed';
            }
        }
        
        // Verify retry handler handled concurrent load
        $this->assertCount($concurrentRequests, $results);
        
        $stats = $retryHandler->getStats();
        $this->assertEquals($concurrentRequests, $stats['total_calls']);
        $this->assertGreaterThanOrEqual(0, $stats['total_retries']);
    }

    /**
     * Test memory usage under concurrent load
     */
    public function testMemoryUsageUnderConcurrentLoad(): void
    {
        $initialMemory = memory_get_usage(true);
        
        $concurrentOperations = 1000;
        $headers = ['content-type' => 'application/grpc+proto'];
        
        // Simulate heavy concurrent load
        for ($i = 0; $i < $concurrentOperations; $i++) {
            $message = json_encode(['name' => "Load{$i}"]);
            $grpcFrame = chr(0) . pack('N', strlen($message)) . $message;
            
            $this->handler->processRequest(
                $this->service,
                'SayHello',
                $grpcFrame,
                $headers
            );
            
            // Periodic garbage collection
            if ($i % 100 === 0) {
                gc_collect_cycles();
            }
        }
        
        $finalMemory = memory_get_usage(true);
        $memoryIncrease = $finalMemory - $initialMemory;
        $memoryPerOperation = $memoryIncrease / $concurrentOperations;
        
        // Memory usage should be reasonable
        $this->assertLessThan(200 * 1024 * 1024, $memoryIncrease); // Less than 200MB
        $this->assertLessThan(2048, $memoryPerOperation); // Less than 2KB per operation
        
        echo "\nConcurrency Memory Test:\n";
        echo "Operations: {$concurrentOperations}\n";
        echo "Memory increase: " . $this->formatBytes($memoryIncrease) . "\n";
        echo "Per operation: " . $this->formatBytes($memoryPerOperation) . "\n";
    }

    /**
     * Test task serialization under concurrent load
     */
    public function testTaskSerializationUnderConcurrentLoad(): void
    {
        $concurrentTasks = 50;
        $tasks = [];
        
        // Create tasks
        for ($i = 0; $i < $concurrentTasks; $i++) {
            $task = new GrpcProcessingTask(
                GreeterService::class,
                'SayHello',
                json_encode(['name' => "Serialize{$i}"]),
                ['content-type' => 'application/grpc+proto']
            );
            $tasks[] = $task;
        }
        
        // Serialize all tasks
        $startTime = microtime(true);
        $serializedTasks = [];
        
        foreach ($tasks as $task) {
            $serialized = $task->__serialize();
            $serializedTasks[] = $serialized;
        }
        
        $serializationTime = microtime(true) - $startTime;
        
        // Deserialize all tasks
        $startTime = microtime(true);
        $deserializedTasks = [];
        
        foreach ($serializedTasks as $serialized) {
            $newTask = new GrpcProcessingTask('', '', '', []);
            $newTask->__unserialize($serialized);
            $deserializedTasks[] = $newTask;
        }
        
        $deserializationTime = microtime(true) - $startTime;
        
        // Verify all tasks were processed
        $this->assertCount($concurrentTasks, $serializedTasks);
        $this->assertCount($concurrentTasks, $deserializedTasks);
        
        // Verify task integrity
        foreach ($deserializedTasks as $i => $task) {
            $this->assertEquals($tasks[$i]->getId(), $task->getId());
            $this->assertEquals($tasks[$i]->getMetadata()['service_class'], $task->getMetadata()['service_class']);
        }
        
        // Performance check
        $this->assertLessThan(1.0, $serializationTime); // Less than 1 second
        $this->assertLessThan(1.0, $deserializationTime); // Less than 1 second
    }

    /**
     * Test engine statistics under concurrent access
     */
    public function testEngineStatsUnderConcurrentAccess(): void
    {
        $concurrentAccess = 100;
        $statsResults = [];
        
        // Simulate concurrent stats access
        for ($i = 0; $i < $concurrentAccess; $i++) {
            // Perform some operations
            $this->engine->processMessage("Stats test {$i}");
            
            // Access stats concurrently
            $stats = $this->engine->getStats();
            $statsResults[] = $stats;
        }
        
        // Verify all stats were retrieved
        $this->assertCount($concurrentAccess, $statsResults);
        
        // Verify stats consistency
        foreach ($statsResults as $stats) {
            $this->assertIsArray($stats);
            $this->assertArrayHasKey('operations_total', $stats);
            $this->assertArrayHasKey('avg_processing_time', $stats);
            $this->assertIsNumeric($stats['operations_total']);
            $this->assertIsNumeric($stats['avg_processing_time']);
        }
        
        // Verify stats are increasing
        $firstStats = $statsResults[0];
        $lastStats = $statsResults[$concurrentAccess - 1];
        $this->assertGreaterThanOrEqual($firstStats['operations_total'], $lastStats['operations_total']);
    }

    /**
     * Test service registration under concurrent load
     */
    public function testServiceRegistrationUnderConcurrentLoad(): void
    {
        $server = $this->factory->createDevelopmentServer();
        $concurrentRegistrations = 20;
        
        // Register services concurrently
        for ($i = 0; $i < $concurrentRegistrations; $i++) {
            $service = new class($i) extends GreeterService {
                private int $id;
                
                public function __construct(int $id)
                {
                    parent::__construct();
                    $this->id = $id;
                }
                
                public function getServiceName(): string
                {
                    return "concurrent.Service{$this->id}";
                }
            };
            
            $server->registerService($service);
        }
        
        // Verify all services were registered
        $services = $server->getServices();
        $this->assertCount($concurrentRegistrations, $services);
        
        // Verify service integrity
        for ($i = 0; $i < $concurrentRegistrations; $i++) {
            $this->assertArrayHasKey("concurrent.Service{$i}", $services);
        }
        
        // Test server stats
        $stats = $server->getStats();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_requests', $stats);
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