<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\GRPC\Tests\Performance;

use PHPUnit\Framework\TestCase;
use HighPerApp\HighPer\GRPC\GrpcServerFactory;
use HighPerApp\HighPer\GRPC\Examples\GreeterService;
use HighPerApp\HighPer\GRPC\Engines\HybridEngine;
use HighPerApp\HighPer\GRPC\Protocol\GrpcProtocolHandler;
use HighPerApp\HighPer\GRPC\Serialization\ProtobufSerializer;
use Psr\Log\NullLogger;

/**
 * Performance tests for gRPC components
 */
class GrpcPerformanceTest extends TestCase
{
    private GrpcServerFactory $factory;
    private GreeterService $service;
    private HybridEngine $engine;
    private GrpcProtocolHandler $handler;
    private ProtobufSerializer $serializer;

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
        $this->serializer = new ProtobufSerializer([], new NullLogger());
    }

    /**
     * Test engine performance with different message sizes
     */
    public function testEnginePerformanceWithDifferentMessageSizes(): void
    {
        $messageSizes = [100, 1000, 10000, 100000];
        $results = [];
        
        foreach ($messageSizes as $size) {
            $message = str_repeat('x', $size);
            
            $startTime = microtime(true);
            $iterations = 1000;
            
            for ($i = 0; $i < $iterations; $i++) {
                $this->engine->processMessage($message);
            }
            
            $endTime = microtime(true);
            $totalTime = $endTime - $startTime;
            $avgTime = $totalTime / $iterations;
            $throughput = $iterations / $totalTime;
            
            $results[$size] = [
                'avg_time' => $avgTime,
                'throughput' => $throughput,
                'total_time' => $totalTime
            ];
        }
        
        // Verify performance characteristics
        $this->assertLessThan(0.001, $results[100]['avg_time']); // Less than 1ms for small messages
        $this->assertGreaterThan(100, $results[100]['throughput']); // At least 100 ops/sec for small messages
        
        // Performance should degrade gracefully with size
        $this->assertLessThan($results[100000]['avg_time'], $results[100]['avg_time'] * 100);
        
        echo "\nEngine Performance Results:\n";
        foreach ($results as $size => $result) {
            printf("Size %d: %.6f ms/op, %.2f ops/sec\n", 
                $size, $result['avg_time'] * 1000, $result['throughput']);
        }
    }

    /**
     * Test serialization performance
     */
    public function testSerializationPerformance(): void
    {
        $message = new MockProtobufMessage('test content');
        $iterations = 10000;
        
        // Test serialization
        $startTime = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $this->serializer->serialize($message);
        }
        $serializationTime = microtime(true) - $startTime;
        
        // Test deserialization
        $data = $this->serializer->serialize($message);
        $startTime = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $this->serializer->deserialize($data, MockProtobufMessage::class);
        }
        $deserializationTime = microtime(true) - $startTime;
        
        $serializationThroughput = $iterations / $serializationTime;
        $deserializationThroughput = $iterations / $deserializationTime;
        
        // Performance assertions
        $this->assertGreaterThan(1000, $serializationThroughput); // At least 1000 ops/sec
        $this->assertGreaterThan(1000, $deserializationThroughput); // At least 1000 ops/sec
        
        echo "\nSerialization Performance:\n";
        printf("Serialization: %.2f ops/sec\n", $serializationThroughput);
        printf("Deserialization: %.2f ops/sec\n", $deserializationThroughput);
    }

    /**
     * Test protocol handler performance
     */
    public function testProtocolHandlerPerformance(): void
    {
        $headers = [
            'content-type' => 'application/grpc+proto',
            'user-agent' => 'performance-test/1.0'
        ];
        
        $message = json_encode(['name' => 'Performance Test']);
        $grpcFrame = chr(0) . pack('N', strlen($message)) . $message;
        
        $iterations = 1000;
        $startTime = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $this->handler->processRequest(
                $this->service,
                'SayHello',
                $grpcFrame,
                $headers
            );
        }
        
        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        $avgTime = $totalTime / $iterations;
        $throughput = $iterations / $totalTime;
        
        // Performance assertions
        $this->assertLessThan(0.01, $avgTime); // Less than 10ms per request
        $this->assertGreaterThan(100, $throughput); // At least 100 requests/sec
        
        echo "\nProtocol Handler Performance:\n";
        printf("Average time: %.3f ms/request\n", $avgTime * 1000);
        printf("Throughput: %.2f requests/sec\n", $throughput);
    }

    /**
     * Test compression performance
     */
    public function testCompressionPerformance(): void
    {
        $data = str_repeat('test data that can be compressed efficiently ', 1000);
        $algorithms = ['gzip', 'deflate'];
        
        foreach ($algorithms as $algorithm) {
            $iterations = 100;
            
            // Test compression
            $startTime = microtime(true);
            for ($i = 0; $i < $iterations; $i++) {
                $compressed = $this->engine->compress($data, $algorithm);
            }
            $compressionTime = microtime(true) - $startTime;
            
            // Test decompression
            $compressed = $this->engine->compress($data, $algorithm);
            $startTime = microtime(true);
            for ($i = 0; $i < $iterations; $i++) {
                $decompressed = $this->engine->decompress($compressed, $algorithm);
            }
            $decompressionTime = microtime(true) - $startTime;
            
            $compressionThroughput = $iterations / $compressionTime;
            $decompressionThroughput = $iterations / $decompressionTime;
            $compressionRatio = strlen($compressed) / strlen($data);
            
            // Performance assertions
            $this->assertGreaterThan(10, $compressionThroughput); // At least 10 ops/sec
            $this->assertGreaterThan(10, $decompressionThroughput); // At least 10 ops/sec
            $this->assertLessThan(0.9, $compressionRatio); // At least 10% compression
            
            echo "\nCompression Performance ({$algorithm}):\n";
            printf("Compression: %.2f ops/sec\n", $compressionThroughput);
            printf("Decompression: %.2f ops/sec\n", $decompressionThroughput);
            printf("Compression ratio: %.2f%%\n", $compressionRatio * 100);
        }
    }

    /**
     * Test memory usage under load
     */
    public function testMemoryUsageUnderLoad(): void
    {
        $initialMemory = memory_get_usage(true);
        
        // Process many requests
        $iterations = 5000;
        $headers = ['content-type' => 'application/grpc+proto'];
        
        for ($i = 0; $i < $iterations; $i++) {
            $message = json_encode(['name' => "User{$i}"]);
            $grpcFrame = chr(0) . pack('N', strlen($message)) . $message;
            
            $this->handler->processRequest(
                $this->service,
                'SayHello',
                $grpcFrame,
                $headers
            );
            
            // Force garbage collection every 1000 iterations
            if ($i % 1000 === 0) {
                gc_collect_cycles();
            }
        }
        
        $finalMemory = memory_get_usage(true);
        $memoryIncrease = $finalMemory - $initialMemory;
        $memoryPerRequest = $memoryIncrease / $iterations;
        
        // Memory usage should be reasonable
        $this->assertLessThan(100 * 1024 * 1024, $memoryIncrease); // Less than 100MB increase
        $this->assertLessThan(1024, $memoryPerRequest); // Less than 1KB per request
        
        echo "\nMemory Usage:\n";
        printf("Initial: %s\n", $this->formatBytes($initialMemory));
        printf("Final: %s\n", $this->formatBytes($finalMemory));
        printf("Increase: %s\n", $this->formatBytes($memoryIncrease));
        printf("Per request: %s\n", $this->formatBytes($memoryPerRequest));
    }

    /**
     * Test concurrent request handling simulation
     */
    public function testConcurrentRequestSimulation(): void
    {
        $headers = ['content-type' => 'application/grpc+proto'];
        $concurrentRequests = 100;
        $startTime = microtime(true);
        
        // Simulate concurrent requests by processing them in quick succession
        for ($i = 0; $i < $concurrentRequests; $i++) {
            $message = json_encode(['name' => "Concurrent{$i}"]);
            $grpcFrame = chr(0) . pack('N', strlen($message)) . $message;
            
            $this->handler->processRequest(
                $this->service,
                'SayHello',
                $grpcFrame,
                $headers
            );
        }
        
        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        $avgTime = $totalTime / $concurrentRequests;
        $throughput = $concurrentRequests / $totalTime;
        
        // Performance assertions for concurrent simulation
        $this->assertLessThan(0.1, $avgTime); // Less than 100ms per request
        $this->assertGreaterThan(10, $throughput); // At least 10 requests/sec
        
        echo "\nConcurrent Request Simulation:\n";
        printf("Total requests: %d\n", $concurrentRequests);
        printf("Total time: %.3f seconds\n", $totalTime);
        printf("Average time: %.3f ms/request\n", $avgTime * 1000);
        printf("Throughput: %.2f requests/sec\n", $throughput);
    }

    /**
     * Test streaming performance
     */
    public function testStreamingPerformance(): void
    {
        $headers = ['content-type' => 'application/grpc+proto'];
        $streamSize = 1000;
        
        // Create request stream
        $requestStream = $this->createRequestStream($streamSize);
        
        $startTime = microtime(true);
        
        $responseStream = $this->handler->processStreamingRequest(
            $this->service,
            'SayHelloStream',
            $requestStream,
            $headers
        );
        
        $responseCount = 0;
        foreach ($responseStream as $response) {
            $responseCount++;
        }
        
        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        $throughput = $responseCount / $totalTime;
        
        // Streaming performance assertions
        $this->assertGreaterThan(0, $responseCount);
        $this->assertGreaterThan(10, $throughput); // At least 10 responses/sec
        
        echo "\nStreaming Performance:\n";
        printf("Stream size: %d\n", $streamSize);
        printf("Responses: %d\n", $responseCount);
        printf("Total time: %.3f seconds\n", $totalTime);
        printf("Throughput: %.2f responses/sec\n", $throughput);
    }

    /**
     * Test cache performance
     */
    public function testCachePerformance(): void
    {
        $serializer = new ProtobufSerializer([
            'cache_enabled' => true,
            'cache_max_size' => 1000
        ], new NullLogger());
        
        $message = new MockProtobufMessage('cached content');
        $iterations = 1000;
        
        // First run (cache misses)
        $startTime = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $serializer->serialize($message);
        }
        $cacheOffTime = microtime(true) - $startTime;
        
        // Second run (cache hits)
        $startTime = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $serializer->serialize($message);
        }
        $cacheOnTime = microtime(true) - $startTime;
        
        $stats = $serializer->getStats();
        $cacheHitRate = $stats['cache_hit_rate'];
        $speedup = $cacheOffTime / $cacheOnTime;
        
        // Cache performance assertions
        $this->assertGreaterThan(50, $cacheHitRate); // At least 50% cache hit rate
        $this->assertGreaterThan(2, $speedup); // At least 2x speedup
        
        echo "\nCache Performance:\n";
        printf("Cache hit rate: %.2f%%\n", $cacheHitRate);
        printf("Cache off time: %.3f seconds\n", $cacheOffTime);
        printf("Cache on time: %.3f seconds\n", $cacheOnTime);
        printf("Speedup: %.2fx\n", $speedup);
    }

    /**
     * Create request stream generator
     */
    private function createRequestStream(int $size): \Generator
    {
        for ($i = 0; $i < $size; $i++) {
            $message = json_encode(['name' => "Stream{$i}"]);
            $grpcFrame = chr(0) . pack('N', strlen($message)) . $message;
            yield $grpcFrame;
        }
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

/**
 * Mock protobuf message for performance testing
 */
class MockProtobufMessage extends \Google\Protobuf\Internal\Message
{
    private string $content = '';
    
    public function __construct(string $content = '')
    {
        parent::__construct();
        $this->content = $content;
    }
    
    public function getContent(): string
    {
        return $this->content;
    }
    
    public function setContent(string $content): void
    {
        $this->content = $content;
    }
    
    public function serializeToString(): string
    {
        return $this->content;
    }
    
    public function mergeFromString(string $data): void
    {
        $this->content = $data;
    }
}