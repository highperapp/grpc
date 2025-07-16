<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\GRPC\Protocol;

use HighPerApp\HighPer\GRPC\Contracts\EngineInterface;
use HighPerApp\HighPer\GRPC\Serialization\ProtobufSerializer;
use HighPerApp\HighPer\GRPC\Exceptions\GrpcException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * gRPC Protocol Handler for HighPer Framework
 * 
 * Handles gRPC protocol specifics including:
 * - HTTP/2 frame processing
 * - Protobuf serialization/deserialization
 * - Streaming support
 * - Error handling
 * - Compression
 */
class GrpcProtocolHandler
{
    private EngineInterface $engine;
    private ProtobufSerializer $serializer;
    private LoggerInterface $logger;
    private array $config;
    private array $supportedCompressions = ['gzip', 'deflate'];
    
    public function __construct(
        EngineInterface $engine,
        array $config = [],
        ?LoggerInterface $logger = null
    ) {
        $this->engine = $engine;
        $this->config = array_merge([
            'max_message_size' => 16 * 1024 * 1024, // 16MB
            'compression_enabled' => true,
            'compression_level' => 6,
            'streaming_enabled' => true,
            'timeout_seconds' => 30,
        ], $config);
        $this->logger = $logger ?? new NullLogger();
        $this->serializer = new ProtobufSerializer();
    }

    /**
     * Process gRPC request with full protocol support
     */
    public function processRequest(
        object $service,
        string $methodName,
        string $requestBody,
        array $headers
    ): array {
        $startTime = microtime(true);
        
        try {
            // Validate headers
            $this->validateGrpcHeaders($headers);
            
            // Extract compression info
            $compression = $this->extractCompression($headers);
            
            // Decompress request if needed
            $decompressedBody = $this->decompressRequest($requestBody, $compression);
            
            // Validate message size
            $this->validateMessageSize($decompressedBody);
            
            // Parse gRPC message frame
            $grpcMessage = $this->parseGrpcMessage($decompressedBody);
            
            // Deserialize protobuf message
            $requestMessage = $this->deserializeRequest($service, $methodName, $grpcMessage);
            
            // Call service method
            $responseMessage = $this->callServiceMethod($service, $methodName, $requestMessage);
            
            // Serialize response
            $serializedResponse = $this->serializeResponse($responseMessage);
            
            // Create gRPC response frame
            $grpcFrame = $this->createGrpcFrame($serializedResponse);
            
            // Compress response if needed
            $compressedFrame = $this->compressResponse($grpcFrame, $compression);
            
            // Create response headers
            $responseHeaders = $this->createResponseHeaders($compression);
            
            $processingTime = microtime(true) - $startTime;
            
            $this->logger->debug('gRPC request processed successfully', [
                'service' => get_class($service),
                'method' => $methodName,
                'request_size' => strlen($requestBody),
                'response_size' => strlen($compressedFrame),
                'processing_time' => $processingTime,
                'compression' => $compression
            ]);
            
            return [
                'headers' => $responseHeaders,
                'body' => $compressedFrame,
                'status' => 0 // gRPC OK
            ];
            
        } catch (\Throwable $e) {
            $this->logger->error('gRPC request processing failed', [
                'service' => get_class($service),
                'method' => $methodName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new GrpcException("gRPC processing failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Process streaming gRPC request
     */
    public function processStreamingRequest(
        object $service,
        string $methodName,
        \Generator $requestStream,
        array $headers
    ): \Generator {
        try {
            $this->validateGrpcHeaders($headers);
            $compression = $this->extractCompression($headers);
            
            // Call streaming service method
            $responseStream = $this->callStreamingServiceMethod($service, $methodName, $requestStream);
            
            // Process streaming responses
            foreach ($responseStream as $responseMessage) {
                $serializedResponse = $this->serializeResponse($responseMessage);
                $grpcFrame = $this->createGrpcFrame($serializedResponse);
                $compressedFrame = $this->compressResponse($grpcFrame, $compression);
                
                yield [
                    'headers' => $this->createResponseHeaders($compression),
                    'body' => $compressedFrame,
                    'status' => 0
                ];
            }
            
        } catch (\Throwable $e) {
            $this->logger->error('gRPC streaming request failed', [
                'service' => get_class($service),
                'method' => $methodName,
                'error' => $e->getMessage()
            ]);
            
            throw new GrpcException("gRPC streaming failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Validate gRPC headers
     */
    private function validateGrpcHeaders(array $headers): void
    {
        $contentType = $headers['content-type'] ?? '';
        
        if (!str_starts_with($contentType, 'application/grpc')) {
            throw new GrpcException('Invalid content-type for gRPC request');
        }
        
        // Validate other gRPC headers
        if (isset($headers['grpc-timeout'])) {
            $this->validateTimeout($headers['grpc-timeout']);
        }
    }

    /**
     * Extract compression type from headers
     */
    private function extractCompression(array $headers): ?string
    {
        $encoding = $headers['grpc-encoding'] ?? null;
        
        if ($encoding && !in_array($encoding, $this->supportedCompressions)) {
            throw new GrpcException("Unsupported compression: {$encoding}");
        }
        
        return $encoding;
    }

    /**
     * Decompress request body
     */
    private function decompressRequest(string $body, ?string $compression): string
    {
        if (!$compression || !$this->config['compression_enabled']) {
            return $body;
        }
        
        return match ($compression) {
            'gzip' => gzdecode($body),
            'deflate' => inflate($body),
            default => $body
        };
    }

    /**
     * Validate message size
     */
    private function validateMessageSize(string $message): void
    {
        $size = strlen($message);
        if ($size > $this->config['max_message_size']) {
            throw new GrpcException("Message size {$size} exceeds maximum {$this->config['max_message_size']}");
        }
    }

    /**
     * Parse gRPC message frame
     */
    private function parseGrpcMessage(string $body): string
    {
        if (strlen($body) < 5) {
            throw new GrpcException('Invalid gRPC message: too short');
        }
        
        // gRPC message format: [Compressed-Flag][Message-Length][Message]
        $compressed = ord($body[0]);
        $length = unpack('N', substr($body, 1, 4))[1];
        $message = substr($body, 5);
        
        if (strlen($message) !== $length) {
            throw new GrpcException('Invalid gRPC message: length mismatch');
        }
        
        return $message;
    }

    /**
     * Create gRPC response frame
     */
    private function createGrpcFrame(string $message): string
    {
        $length = strlen($message);
        return chr(0) . pack('N', $length) . $message;
    }

    /**
     * Deserialize protobuf request
     */
    private function deserializeRequest(object $service, string $methodName, string $message): object
    {
        $requestClass = $this->getRequestClass($service, $methodName);
        return $this->serializer->deserialize($message, $requestClass);
    }

    /**
     * Serialize protobuf response
     */
    private function serializeResponse(object $response): string
    {
        return $this->serializer->serialize($response);
    }

    /**
     * Call service method
     */
    private function callServiceMethod(object $service, string $methodName, object $request): object
    {
        if (!method_exists($service, $methodName)) {
            throw new GrpcException("Method {$methodName} not found in service");
        }
        
        return $service->{$methodName}($request);
    }

    /**
     * Call streaming service method
     */
    private function callStreamingServiceMethod(object $service, string $methodName, \Generator $requestStream): \Generator
    {
        if (!method_exists($service, $methodName)) {
            throw new GrpcException("Streaming method {$methodName} not found in service");
        }
        
        return $service->{$methodName}($requestStream);
    }

    /**
     * Compress response
     */
    private function compressResponse(string $response, ?string $compression): string
    {
        if (!$compression || !$this->config['compression_enabled']) {
            return $response;
        }
        
        return match ($compression) {
            'gzip' => gzencode($response, $this->config['compression_level']),
            'deflate' => deflate($response, $this->config['compression_level']),
            default => $response
        };
    }

    /**
     * Create response headers
     */
    private function createResponseHeaders(?string $compression): array
    {
        $headers = [
            'content-type' => 'application/grpc+proto',
            'grpc-status' => '0',
            'grpc-message' => ''
        ];
        
        if ($compression) {
            $headers['grpc-encoding'] = $compression;
        }
        
        return $headers;
    }

    /**
     * Get request class for method
     */
    private function getRequestClass(object $service, string $methodName): string
    {
        // This would be implemented based on service metadata
        // For now, assume a naming convention
        $serviceClass = get_class($service);
        $namespace = substr($serviceClass, 0, strrpos($serviceClass, '\\'));
        return $namespace . '\\' . ucfirst($methodName) . 'Request';
    }

    /**
     * Validate timeout header
     */
    private function validateTimeout(string $timeout): void
    {
        if (!preg_match('/^\d+[HMS]$/', $timeout)) {
            throw new GrpcException("Invalid timeout format: {$timeout}");
        }
    }

    /**
     * Serialize health check response
     */
    public function serializeHealthResponse(array $healthStatus): string
    {
        // Simple health response serialization
        // In a real implementation, this would use proper protobuf
        return json_encode($healthStatus);
    }

    /**
     * Process reflection request
     */
    public function processReflection(string $body, array $services): string
    {
        // gRPC reflection protocol implementation
        // This would handle service discovery requests
        
        $reflection = [
            'services' => array_keys($services),
            'methods' => []
        ];
        
        foreach ($services as $serviceName => $service) {
            if (method_exists($service, 'getMethods')) {
                $reflection['methods'][$serviceName] = $service->getMethods();
            }
        }
        
        return json_encode($reflection);
    }

    /**
     * Get protocol handler statistics
     */
    public function getStats(): array
    {
        return [
            'supported_compressions' => $this->supportedCompressions,
            'max_message_size' => $this->config['max_message_size'],
            'compression_enabled' => $this->config['compression_enabled'],
            'streaming_enabled' => $this->config['streaming_enabled'],
            'engine' => get_class($this->engine)
        ];
    }
}