<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\GRPC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use HighPerApp\HighPer\GRPC\Parallel\GrpcProcessingTask;
use HighPerApp\HighPer\GRPC\Examples\GreeterService;
use Amp\Parallel\Worker\Environment;

/**
 * Unit tests for GrpcProcessingTask
 */
class GrpcProcessingTaskTest extends TestCase
{
    private GrpcProcessingTask $task;
    private Environment $environment;

    protected function setUp(): void
    {
        $this->environment = $this->createMock(Environment::class);
        $this->environment->method('get')->willReturn('test-worker');
        
        $this->task = new GrpcProcessingTask(
            GreeterService::class,
            'SayHello',
            json_encode(['name' => 'World']),
            ['content-type' => 'application/grpc+proto'],
            [
                'timeout' => 30,
                'memory_limit' => '128M',
                'enable_logging' => false,
                'rust_acceleration' => false
            ]
        );
    }

    public function testTaskInitialization(): void
    {
        $this->assertInstanceOf(GrpcProcessingTask::class, $this->task);
    }

    public function testTaskMetadata(): void
    {
        $metadata = $this->task->getMetadata();
        
        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('service_class', $metadata);
        $this->assertArrayHasKey('method_name', $metadata);
        $this->assertArrayHasKey('request_size', $metadata);
        $this->assertArrayHasKey('header_count', $metadata);
        $this->assertArrayHasKey('config', $metadata);
        $this->assertArrayHasKey('start_time', $metadata);
        
        $this->assertEquals(GreeterService::class, $metadata['service_class']);
        $this->assertEquals('SayHello', $metadata['method_name']);
        $this->assertGreaterThan(0, $metadata['request_size']);
    }

    public function testTaskId(): void
    {
        $id = $this->task->getId();
        
        $this->assertIsString($id);
        $this->assertNotEmpty($id);
        
        // Same task should have same ID
        $sameTask = new GrpcProcessingTask(
            GreeterService::class,
            'SayHello',
            json_encode(['name' => 'World']),
            ['content-type' => 'application/grpc+proto']
        );
        
        $this->assertEquals($id, $sameTask->getId());
    }

    public function testTaskComplexity(): void
    {
        $complexity = $this->task->getComplexity();
        
        $this->assertIsInt($complexity);
        $this->assertGreaterThan(0, $complexity);
        
        // Test batch method complexity
        $batchTask = new GrpcProcessingTask(
            GreeterService::class,
            'SayHelloBatch',
            json_encode(['names' => ['Alice', 'Bob']]),
            ['content-type' => 'application/grpc+proto']
        );
        
        $this->assertGreaterThan($complexity, $batchTask->getComplexity());
    }

    public function testTaskCacheability(): void
    {
        // Test cacheable method
        $getTask = new GrpcProcessingTask(
            GreeterService::class,
            'get',
            json_encode(['id' => 1]),
            ['content-type' => 'application/grpc+proto']
        );
        
        $this->assertTrue($getTask->isCacheable());
        $this->assertNotEmpty($getTask->getCacheKey());
        
        // Test non-cacheable method
        $this->assertFalse($this->task->isCacheable());
        $this->assertEmpty($this->task->getCacheKey());
    }

    public function testTaskTimeout(): void
    {
        $timeout = $this->task->getTimeout();
        
        $this->assertIsInt($timeout);
        $this->assertEquals(30, $timeout);
    }

    public function testTaskRetryLogic(): void
    {
        $grpcException = new \HighPerApp\HighPer\GRPC\Exceptions\GrpcException('Test error', 14); // UNAVAILABLE
        $this->assertTrue($this->task->shouldRetry($grpcException));
        
        $nonRetryableException = new \HighPerApp\HighPer\GRPC\Exceptions\GrpcException('Invalid arg', 3); // INVALID_ARGUMENT
        $this->assertFalse($this->task->shouldRetry($nonRetryableException));
        
        $timeoutException = new \Exception('Connection timeout');
        $this->assertTrue($this->task->shouldRetry($timeoutException));
    }

    public function testTaskRetryDelay(): void
    {
        $delay = $this->task->getRetryDelay();
        
        $this->assertIsFloat($delay);
        $this->assertEquals(0.1, $delay);
    }

    public function testTaskMaxRetries(): void
    {
        $maxRetries = $this->task->getMaxRetries();
        
        $this->assertIsInt($maxRetries);
        $this->assertEquals(3, $maxRetries);
    }

    public function testTaskSerialization(): void
    {
        $serialized = $this->task->__serialize();
        
        $this->assertIsArray($serialized);
        $this->assertArrayHasKey('serviceClass', $serialized);
        $this->assertArrayHasKey('methodName', $serialized);
        $this->assertArrayHasKey('requestData', $serialized);
        $this->assertArrayHasKey('headers', $serialized);
        $this->assertArrayHasKey('config', $serialized);
        $this->assertArrayHasKey('startTime', $serialized);
        
        // Test unserialization
        $newTask = new GrpcProcessingTask('', '', '', []);
        $newTask->__unserialize($serialized);
        
        $this->assertEquals($this->task->getId(), $newTask->getId());
        $this->assertEquals($this->task->getMetadata()['service_class'], $newTask->getMetadata()['service_class']);
    }

    public function testTaskRunWithValidService(): void
    {
        // Note: This test would require a full AMPHP environment
        // For now, we test the basic structure
        $this->assertTrue(class_exists(GreeterService::class));
    }

    public function testTaskRunWithInvalidService(): void
    {
        $invalidTask = new GrpcProcessingTask(
            'NonExistentService',
            'someMethod',
            json_encode(['test' => 'data']),
            ['content-type' => 'application/grpc+proto']
        );
        
        $this->assertEquals('NonExistentService', $invalidTask->getMetadata()['service_class']);
        $this->assertFalse(class_exists('NonExistentService'));
    }

    public function testTaskConfigurationOverride(): void
    {
        $customConfig = [
            'timeout' => 60,
            'memory_limit' => '256M',
            'enable_logging' => true
        ];
        
        $task = new GrpcProcessingTask(
            GreeterService::class,
            'SayHello',
            json_encode(['name' => 'World']),
            ['content-type' => 'application/grpc+proto'],
            $customConfig
        );
        
        $metadata = $task->getMetadata();
        
        $this->assertEquals(60, $metadata['config']['timeout']);
        $this->assertEquals('256M', $metadata['config']['memory_limit']);
        $this->assertTrue($metadata['config']['enable_logging']);
    }

    public function testTaskStreamingMethod(): void
    {
        $streamTask = new GrpcProcessingTask(
            GreeterService::class,
            'SayHelloStream',
            json_encode(['name' => 'World']),
            ['content-type' => 'application/grpc+proto']
        );
        
        $complexity = $streamTask->getComplexity();
        
        // Streaming methods should have higher complexity
        $this->assertGreaterThan($this->task->getComplexity(), $complexity);
    }

    public function testTaskLargeRequest(): void
    {
        $largeData = str_repeat('x', 10000);
        $largeTask = new GrpcProcessingTask(
            GreeterService::class,
            'SayHello',
            json_encode(['name' => $largeData]),
            ['content-type' => 'application/grpc+proto']
        );
        
        $complexity = $largeTask->getComplexity();
        
        // Large requests should have higher complexity
        $this->assertGreaterThan($this->task->getComplexity(), $complexity);
    }

    public function testTaskMemoryConfiguration(): void
    {
        $memoryTask = new GrpcProcessingTask(
            GreeterService::class,
            'SayHello',
            json_encode(['name' => 'World']),
            ['content-type' => 'application/grpc+proto'],
            ['memory_limit' => '512M']
        );
        
        $metadata = $memoryTask->getMetadata();
        
        $this->assertEquals('512M', $metadata['config']['memory_limit']);
    }

    public function testTaskEngineConfiguration(): void
    {
        $engineTask = new GrpcProcessingTask(
            GreeterService::class,
            'SayHello',
            json_encode(['name' => 'World']),
            ['content-type' => 'application/grpc+proto'],
            [
                'rust_acceleration' => true,
                'compression_enabled' => false
            ]
        );
        
        $metadata = $engineTask->getMetadata();
        
        $this->assertTrue($metadata['config']['rust_acceleration']);
        $this->assertFalse($metadata['config']['compression_enabled']);
    }
}