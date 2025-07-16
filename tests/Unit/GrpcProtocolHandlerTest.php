<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\GRPC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use HighPerApp\HighPer\GRPC\Protocol\GrpcProtocolHandler;
use HighPerApp\HighPer\GRPC\Engines\HybridEngine;
use HighPerApp\HighPer\GRPC\Examples\GreeterService;
use HighPerApp\HighPer\GRPC\Exceptions\GrpcException;
use Psr\Log\NullLogger;

/**
 * Unit tests for GrpcProtocolHandler
 */
class GrpcProtocolHandlerTest extends TestCase
{
    private GrpcProtocolHandler $handler;
    private HybridEngine $engine;
    private GreeterService $service;

    protected function setUp(): void
    {
        $this->engine = new HybridEngine([
            'rust_acceleration' => false,
            'fallback_to_php' => true
        ], new NullLogger());
        
        $this->handler = new GrpcProtocolHandler(
            $this->engine,
            [
                'max_message_size' => 1024 * 1024,
                'compression_enabled' => true,
                'streaming_enabled' => true
            ],
            new NullLogger()
        );
        
        $this->service = new GreeterService(new NullLogger());
    }

    public function testHandlerInitialization(): void
    {
        $this->assertInstanceOf(GrpcProtocolHandler::class, $this->handler);
    }

    public function testProcessValidRequest(): void
    {
        $headers = [
            'content-type' => 'application/grpc+proto',
            'user-agent' => 'grpc-test/1.0'
        ];
        
        // Create a simple gRPC message frame
        $message = json_encode(['name' => 'World']);
        $grpcFrame = chr(0) . pack('N', strlen($message)) . $message;
        
        $result = $this->handler->processRequest(
            $this->service,
            'SayHello',
            $grpcFrame,
            $headers
        );
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('headers', $result);
        $this->assertArrayHasKey('body', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertEquals(0, $result['status']);
    }

    public function testProcessRequestWithInvalidHeaders(): void
    {
        $this->expectException(GrpcException::class);
        $this->expectExceptionMessage('Invalid content-type for gRPC request');
        
        $headers = [
            'content-type' => 'application/json', // Invalid for gRPC
        ];
        
        $this->handler->processRequest(
            $this->service,
            'SayHello',
            'test message',
            $headers
        );
    }

    public function testProcessRequestWithCompression(): void
    {
        $headers = [
            'content-type' => 'application/grpc+proto',
            'grpc-encoding' => 'gzip'
        ];
        
        $message = json_encode(['name' => 'World']);
        $grpcFrame = chr(0) . pack('N', strlen($message)) . $message;
        $compressedFrame = gzencode($grpcFrame);
        
        $result = $this->handler->processRequest(
            $this->service,
            'SayHello',
            $compressedFrame,
            $headers
        );
        
        $this->assertIsArray($result);
        $this->assertEquals(0, $result['status']);
        $this->assertArrayHasKey('grpc-encoding', $result['headers']);
    }

    public function testProcessRequestWithUnsupportedCompression(): void
    {
        $this->expectException(GrpcException::class);
        $this->expectExceptionMessage('Unsupported compression');
        
        $headers = [
            'content-type' => 'application/grpc+proto',
            'grpc-encoding' => 'brotli' // Unsupported compression
        ];
        
        $this->handler->processRequest(
            $this->service,
            'SayHello',
            'test message',
            $headers
        );
    }

    public function testProcessRequestWithInvalidTimeout(): void
    {
        $this->expectException(GrpcException::class);
        $this->expectExceptionMessage('Invalid timeout format');
        
        $headers = [
            'content-type' => 'application/grpc+proto',
            'grpc-timeout' => 'invalid_timeout'
        ];
        
        $this->handler->processRequest(
            $this->service,
            'SayHello',
            'test message',
            $headers
        );
    }

    public function testProcessRequestWithValidTimeout(): void
    {
        $headers = [
            'content-type' => 'application/grpc+proto',
            'grpc-timeout' => '30S' // Valid timeout format
        ];
        
        $message = json_encode(['name' => 'World']);
        $grpcFrame = chr(0) . pack('N', strlen($message)) . $message;
        
        $result = $this->handler->processRequest(
            $this->service,
            'SayHello',
            $grpcFrame,
            $headers
        );
        
        $this->assertIsArray($result);
        $this->assertEquals(0, $result['status']);
    }

    public function testProcessStreamingRequest(): void
    {
        $headers = [
            'content-type' => 'application/grpc+proto'
        ];
        
        // Create a generator that yields request messages
        $requestStream = $this->createRequestStream();
        
        $responseStream = $this->handler->processStreamingRequest(
            $this->service,
            'SayHelloStream',
            $requestStream,
            $headers
        );
        
        $this->assertInstanceOf(\Generator::class, $responseStream);
        
        $responses = [];
        foreach ($responseStream as $response) {
            $responses[] = $response;
        }
        
        $this->assertNotEmpty($responses);
        $this->assertIsArray($responses[0]);
        $this->assertArrayHasKey('headers', $responses[0]);
        $this->assertArrayHasKey('body', $responses[0]);
        $this->assertArrayHasKey('status', $responses[0]);
    }

    public function testProcessReflection(): void
    {
        $services = [
            'greeter' => $this->service
        ];
        
        $reflectionBody = json_encode(['list_services' => true]);
        
        $result = $this->handler->processReflection($reflectionBody, $services);
        
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        
        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('services', $decoded);
    }

    public function testSerializeHealthResponse(): void
    {
        $healthStatus = [
            'status' => 'SERVING',
            'timestamp' => time()
        ];
        
        $result = $this->handler->serializeHealthResponse($healthStatus);
        
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        
        $decoded = json_decode($result, true);
        $this->assertEquals('SERVING', $decoded['status']);
    }

    public function testGetStats(): void
    {
        $stats = $this->handler->getStats();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('supported_compressions', $stats);
        $this->assertArrayHasKey('max_message_size', $stats);
        $this->assertArrayHasKey('compression_enabled', $stats);
        $this->assertArrayHasKey('streaming_enabled', $stats);
        $this->assertArrayHasKey('engine', $stats);
        
        $this->assertIsArray($stats['supported_compressions']);
        $this->assertContains('gzip', $stats['supported_compressions']);
        $this->assertContains('deflate', $stats['supported_compressions']);
    }

    public function testMessageSizeValidation(): void
    {
        $handler = new GrpcProtocolHandler(
            $this->engine,
            ['max_message_size' => 10], // Very small limit
            new NullLogger()
        );
        
        $this->expectException(GrpcException::class);
        $this->expectExceptionMessage('Message size');
        
        $headers = [
            'content-type' => 'application/grpc+proto'
        ];
        
        $largeMessage = str_repeat('x', 100);
        $grpcFrame = chr(0) . pack('N', strlen($largeMessage)) . $largeMessage;
        
        $handler->processRequest(
            $this->service,
            'SayHello',
            $grpcFrame,
            $headers
        );
    }

    public function testGrpcMessageParsing(): void
    {
        $message = 'test message';
        $grpcFrame = chr(0) . pack('N', strlen($message)) . $message;
        
        // Test with invalid frame (too short)
        $this->expectException(GrpcException::class);
        $this->expectExceptionMessage('Invalid gRPC message: too short');
        
        $headers = [
            'content-type' => 'application/grpc+proto'
        ];
        
        $this->handler->processRequest(
            $this->service,
            'SayHello',
            'x', // Too short to be valid gRPC frame
            $headers
        );
    }

    public function testGrpcMessageLengthMismatch(): void
    {
        // Create frame with incorrect length
        $message = 'short';
        $grpcFrame = chr(0) . pack('N', 100) . $message; // Length says 100 but message is much shorter
        
        $this->expectException(GrpcException::class);
        $this->expectExceptionMessage('Invalid gRPC message: length mismatch');
        
        $headers = [
            'content-type' => 'application/grpc+proto'
        ];
        
        $this->handler->processRequest(
            $this->service,
            'SayHello',
            $grpcFrame,
            $headers
        );
    }

    /**
     * Create a simple request stream generator
     */
    private function createRequestStream(): \Generator
    {
        $requests = [
            json_encode(['name' => 'Alice']),
            json_encode(['name' => 'Bob']),
            json_encode(['name' => 'Charlie'])
        ];
        
        foreach ($requests as $request) {
            $grpcFrame = chr(0) . pack('N', strlen($request)) . $request;
            yield $grpcFrame;
        }
    }
}