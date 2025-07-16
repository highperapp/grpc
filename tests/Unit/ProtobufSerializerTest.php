<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\GRPC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use HighPerApp\HighPer\GRPC\Serialization\ProtobufSerializer;
use HighPerApp\HighPer\GRPC\Exceptions\GrpcException;
use Google\Protobuf\Internal\Message;

/**
 * Mock protobuf message for testing
 */
class MockProtobufMessage extends Message
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
    
    public function serializeToJsonString(): string
    {
        return json_encode(['content' => $this->content]);
    }
    
    public function mergeFromJsonString($json, $ignore_unknown = false): void
    {
        $data = json_decode($json, true);
        $this->content = $data['content'] ?? '';
    }
}

/**
 * Unit tests for ProtobufSerializer
 */
class ProtobufSerializerTest extends TestCase
{
    private ProtobufSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new ProtobufSerializer([
            'cache_enabled' => true,
            'validate_messages' => true
        ]);
    }

    public function testSerializeValidMessage(): void
    {
        $message = new MockProtobufMessage('test content');
        $serialized = $this->serializer->serialize($message);
        
        $this->assertEquals('test content', $serialized);
    }

    public function testSerializeInvalidMessage(): void
    {
        $this->expectException(GrpcException::class);
        $this->expectExceptionMessage('Object must be instance of Google\Protobuf\Internal\Message');
        
        $invalidMessage = new \stdClass();
        $this->serializer->serialize($invalidMessage);
    }

    public function testDeserializeValidData(): void
    {
        $data = 'test content';
        $messageClass = MockProtobufMessage::class;
        
        $message = $this->serializer->deserialize($data, $messageClass);
        
        $this->assertInstanceOf(MockProtobufMessage::class, $message);
        $this->assertEquals('test content', $message->getContent());
    }

    public function testDeserializeInvalidClass(): void
    {
        $this->expectException(GrpcException::class);
        $this->expectExceptionMessage('Invalid message class');
        
        $data = 'test content';
        $this->serializer->deserialize($data, 'NonExistentClass');
    }

    public function testSerializeToJson(): void
    {
        $message = new MockProtobufMessage('test content');
        $json = $this->serializer->serializeToJson($message);
        
        $this->assertEquals('{"content":"test content"}', $json);
    }

    public function testDeserializeFromJson(): void
    {
        $json = '{"content":"test content"}';
        $messageClass = MockProtobufMessage::class;
        
        $message = $this->serializer->deserializeFromJson($json, $messageClass);
        
        $this->assertInstanceOf(MockProtobufMessage::class, $message);
        $this->assertEquals('test content', $message->getContent());
    }

    public function testSerializationCaching(): void
    {
        $message1 = new MockProtobufMessage('cached content');
        $message2 = new MockProtobufMessage('cached content');
        
        // First serialization
        $serialized1 = $this->serializer->serialize($message1);
        $stats1 = $this->serializer->getStats();
        
        // Second serialization (should hit cache)
        $serialized2 = $this->serializer->serialize($message2);
        $stats2 = $this->serializer->getStats();
        
        $this->assertEquals($serialized1, $serialized2);
        $this->assertGreaterThan($stats1['cache_hits'], $stats2['cache_hits']);
    }

    public function testDeserializationCaching(): void
    {
        $data = 'cached content';
        $messageClass = MockProtobufMessage::class;
        
        // First deserialization
        $message1 = $this->serializer->deserialize($data, $messageClass);
        $stats1 = $this->serializer->getStats();
        
        // Second deserialization (should hit cache)
        $message2 = $this->serializer->deserialize($data, $messageClass);
        $stats2 = $this->serializer->getStats();
        
        $this->assertEquals($message1->getContent(), $message2->getContent());
        $this->assertGreaterThan($stats1['cache_hits'], $stats2['cache_hits']);
    }

    public function testSerializerStats(): void
    {
        $message = new MockProtobufMessage('test content');
        $this->serializer->serialize($message);
        
        $stats = $this->serializer->getStats();
        
        $this->assertArrayHasKey('serializations', $stats);
        $this->assertArrayHasKey('deserializations', $stats);
        $this->assertArrayHasKey('cache_hits', $stats);
        $this->assertArrayHasKey('cache_misses', $stats);
        $this->assertArrayHasKey('cache_hit_rate', $stats);
        
        $this->assertEquals(1, $stats['serializations']);
        $this->assertGreaterThanOrEqual(0, $stats['cache_hit_rate']);
    }

    public function testCacheClear(): void
    {
        $message = new MockProtobufMessage('test content');
        $this->serializer->serialize($message);
        
        $statsBefore = $this->serializer->getStats();
        $this->assertGreaterThan(0, $statsBefore['cache_size']);
        
        $this->serializer->clearCache();
        
        $statsAfter = $this->serializer->getStats();
        $this->assertEquals(0, $statsAfter['cache_size']);
    }

    public function testSerializerIsReady(): void
    {
        $this->assertTrue($this->serializer->isReady());
    }

    public function testSerializerWarmUp(): void
    {
        $messageClasses = [MockProtobufMessage::class];
        $this->serializer->warmUp($messageClasses);
        
        $stats = $this->serializer->getStats();
        $this->assertGreaterThan(0, $stats['class_cache_size']);
    }
}