<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\GRPC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use HighPerApp\HighPer\GRPC\Engines\HybridEngine;
use HighPerApp\HighPer\GRPC\Exceptions\GrpcException;
use Psr\Log\NullLogger;

/**
 * Unit tests for HybridEngine
 */
class HybridEngineTest extends TestCase
{
    private HybridEngine $engine;

    protected function setUp(): void
    {
        // Configure for PHP-only mode to avoid Rust FFI dependencies in tests
        $this->engine = new HybridEngine([
            'rust_acceleration' => false,
            'fallback_to_php' => true,
            'optimization_level' => 'balanced'
        ], new NullLogger());
    }

    public function testEngineInitialization(): void
    {
        $this->assertInstanceOf(HybridEngine::class, $this->engine);
        $this->assertTrue($this->engine->isReady());
    }

    public function testProcessMessage(): void
    {
        $testMessage = 'test message';
        $result = $this->engine->processMessage($testMessage);
        
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function testSerializeMessage(): void
    {
        $message = new MockProtobufMessageForEngine('test content');
        $serialized = $this->engine->serializeMessage($message);
        
        $this->assertIsString($serialized);
        $this->assertEquals('test content', $serialized);
    }

    public function testDeserializeMessage(): void
    {
        $data = 'test content';
        $messageClass = MockProtobufMessageForEngine::class;
        
        $message = $this->engine->deserializeMessage($data, $messageClass);
        
        $this->assertInstanceOf(MockProtobufMessageForEngine::class, $message);
        $this->assertEquals('test content', $message->getContent());
    }

    public function testCompress(): void
    {
        $data = 'test data for compression';
        $compressed = $this->engine->compress($data, 'gzip');
        
        $this->assertIsString($compressed);
        $this->assertNotEquals($data, $compressed);
    }

    public function testDecompress(): void
    {
        $data = 'test data for compression';
        $compressed = $this->engine->compress($data, 'gzip');
        $decompressed = $this->engine->decompress($compressed, 'gzip');
        
        $this->assertEquals($data, $decompressed);
    }

    public function testEngineStats(): void
    {
        $this->engine->processMessage('test');
        
        $stats = $this->engine->getStats();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('operations_total', $stats);
        $this->assertArrayHasKey('engine_type', $stats);
        $this->assertArrayHasKey('rust_ffi_available', $stats);
        
        $this->assertGreaterThan(0, $stats['operations_total']);
        $this->assertEquals('pure_php', $stats['engine_type']);
        $this->assertFalse($stats['rust_ffi_available']);
    }

    public function testActiveEngine(): void
    {
        $activeEngine = $this->engine->getActiveEngine();
        
        $this->assertNotNull($activeEngine);
        $this->assertTrue(method_exists($activeEngine, 'processMessage'));
    }

    public function testWarmUp(): void
    {
        $this->engine->warmUp();
        
        // Should not throw any exceptions
        $this->assertTrue($this->engine->isReady());
    }

    public function testCleanup(): void
    {
        $this->engine->cleanup();
        
        // Should not throw any exceptions
        $this->assertTrue(true);
    }

    public function testFallbackBehavior(): void
    {
        // Test that fallback works when primary engine fails
        $engine = new HybridEngine([
            'rust_acceleration' => false, // Force PHP fallback
            'fallback_to_php' => true,
            'auto_fallback' => true
        ], new NullLogger());

        $result = $engine->processMessage('test message');
        
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function testUnsupportedCompression(): void
    {
        $this->expectException(GrpcException::class);
        $this->expectExceptionMessage('Unsupported compression algorithm');
        
        $this->engine->compress('test', 'unsupported');
    }

    public function testUnsupportedDecompression(): void
    {
        $this->expectException(GrpcException::class);
        $this->expectExceptionMessage('Unsupported decompression algorithm');
        
        $this->engine->decompress('test', 'unsupported');
    }

    public function testInvalidMessageClassDeserialization(): void
    {
        $this->expectException(GrpcException::class);
        
        $this->engine->deserializeMessage('test', 'NonExistentClass');
    }

    public function testPerformanceTracking(): void
    {
        // Perform multiple operations
        for ($i = 0; $i < 5; $i++) {
            $this->engine->processMessage("test message {$i}");
        }
        
        $stats = $this->engine->getStats();
        
        $this->assertEquals(5, $stats['operations_total']);
        $this->assertGreaterThan(0, $stats['avg_processing_time']);
    }

    public function testMemoryTracking(): void
    {
        $statsBefore = $this->engine->getStats();
        
        // Process a large message
        $largeMessage = str_repeat('test', 1000);
        $this->engine->processMessage($largeMessage);
        
        $statsAfter = $this->engine->getStats();
        
        $this->assertGreaterThanOrEqual($statsBefore['operations_total'], $statsAfter['operations_total']);
    }
}

/**
 * Mock protobuf message class for testing
 */
class MockProtobufMessageForEngine extends \Google\Protobuf\Internal\Message
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
    
    public function mergeFromString($data): void
    {
        $this->content = $data;
    }
}