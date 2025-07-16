<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\GRPC\Examples;

use HighPerApp\HighPer\GRPC\Contracts\GrpcServiceInterface;
use HighPerApp\HighPer\GRPC\Exceptions\GrpcException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Example Greeter Service
 * 
 * Demonstrates basic gRPC service implementation following
 * HighPer framework patterns and gRPC service contract.
 */
class GreeterService implements GrpcServiceInterface
{
    private LoggerInterface $logger;
    private array $stats = [
        'requests_total' => 0,
        'requests_success' => 0,
        'requests_error' => 0,
        'avg_response_time' => 0.0
    ];

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Get service name
     */
    public function getServiceName(): string
    {
        return 'helloworld.Greeter';
    }

    /**
     * Get service version
     */
    public function getServiceVersion(): string
    {
        return '1.0.0';
    }

    /**
     * Get available methods
     */
    public function getMethods(): array
    {
        return [
            'SayHello' => [
                'request_type' => 'helloworld.HelloRequest',
                'response_type' => 'helloworld.HelloReply',
                'streaming' => false,
                'client_streaming' => false,
                'server_streaming' => false
            ],
            'SayHelloStream' => [
                'request_type' => 'helloworld.HelloRequest',
                'response_type' => 'helloworld.HelloReply',
                'streaming' => true,
                'client_streaming' => false,
                'server_streaming' => true
            ],
            'SayHelloBatch' => [
                'request_type' => 'helloworld.HelloBatchRequest',
                'response_type' => 'helloworld.HelloBatchReply',
                'streaming' => false,
                'client_streaming' => false,
                'server_streaming' => false
            ]
        ];
    }

    /**
     * Check if service is healthy
     */
    public function isHealthy(): bool
    {
        return true;
    }

    /**
     * Get service metadata
     */
    public function getMetadata(): array
    {
        return [
            'description' => 'Simple greeter service example',
            'version' => $this->getServiceVersion(),
            'author' => 'HighPer Framework',
            'stats' => $this->stats
        ];
    }

    /**
     * Called when service is registered
     */
    public function onRegister(): void
    {
        $this->logger->info('Greeter service registered');
    }

    /**
     * Called when service is unregistered
     */
    public function onUnregister(): void
    {
        $this->logger->info('Greeter service unregistered');
    }

    /**
     * Called before processing a request
     */
    public function beforeRequest(string $method, object $request): void
    {
        $this->stats['requests_total']++;
        $this->logger->debug("Processing {$method} request", [
            'method' => $method,
            'request_type' => get_class($request)
        ]);
    }

    /**
     * Called after processing a request
     */
    public function afterRequest(string $method, object $request, object $response): void
    {
        $this->stats['requests_success']++;
        $this->logger->debug("Completed {$method} request", [
            'method' => $method,
            'response_type' => get_class($response)
        ]);
    }

    /**
     * Called when an error occurs
     */
    public function onError(string $method, object $request, \Throwable $error): void
    {
        $this->stats['requests_error']++;
        $this->logger->error("Error in {$method} request", [
            'method' => $method,
            'error' => $error->getMessage(),
            'request_type' => get_class($request)
        ]);
    }

    /**
     * Say hello method
     */
    public function SayHello(object $request): object
    {
        $startTime = microtime(true);
        
        try {
            // Validate request (simplified - would use actual protobuf validation)
            if (!$this->hasProperty($request, 'name')) {
                throw GrpcException::invalidArgument('Request must have name field');
            }
            
            $name = $this->getProperty($request, 'name');
            
            if (empty($name)) {
                throw GrpcException::invalidArgument('Name cannot be empty');
            }
            
            // Create response (simplified - would use actual protobuf message)
            $response = $this->createResponse('HelloReply', [
                'message' => "Hello, {$name}!"
            ]);
            
            $this->updateResponseTime($startTime);
            
            return $response;
            
        } catch (\Throwable $e) {
            $this->logger->error('SayHello failed', [
                'error' => $e->getMessage(),
                'request' => $this->serializeForLogging($request)
            ]);
            
            throw $e;
        }
    }

    /**
     * Say hello stream method
     */
    public function SayHelloStream(object $request): \Generator
    {
        $startTime = microtime(true);
        
        try {
            if (!$this->hasProperty($request, 'name')) {
                throw GrpcException::invalidArgument('Request must have name field');
            }
            
            $name = $this->getProperty($request, 'name');
            
            if (empty($name)) {
                throw GrpcException::invalidArgument('Name cannot be empty');
            }
            
            // Stream multiple responses
            for ($i = 1; $i <= 5; $i++) {
                $response = $this->createResponse('HelloReply', [
                    'message' => "Hello #{$i}, {$name}!"
                ]);
                
                yield $response;
                
                // Small delay between responses
                usleep(100000); // 100ms
            }
            
            $this->updateResponseTime($startTime);
            
        } catch (\Throwable $e) {
            $this->logger->error('SayHelloStream failed', [
                'error' => $e->getMessage(),
                'request' => $this->serializeForLogging($request)
            ]);
            
            throw $e;
        }
    }

    /**
     * Say hello batch method
     */
    public function SayHelloBatch(object $request): object
    {
        $startTime = microtime(true);
        
        try {
            if (!$this->hasProperty($request, 'names')) {
                throw GrpcException::invalidArgument('Request must have names field');
            }
            
            $names = $this->getProperty($request, 'names');
            
            if (!is_array($names) || empty($names)) {
                throw GrpcException::invalidArgument('Names must be non-empty array');
            }
            
            $replies = [];
            foreach ($names as $name) {
                if (empty($name)) {
                    $replies[] = "Error: Empty name";
                } else {
                    $replies[] = "Hello, {$name}!";
                }
            }
            
            $response = $this->createResponse('HelloBatchReply', [
                'messages' => $replies
            ]);
            
            $this->updateResponseTime($startTime);
            
            return $response;
            
        } catch (\Throwable $e) {
            $this->logger->error('SayHelloBatch failed', [
                'error' => $e->getMessage(),
                'request' => $this->serializeForLogging($request)
            ]);
            
            throw $e;
        }
    }

    /**
     * Health check method
     */
    public function health(): object
    {
        return $this->createResponse('HealthCheckResponse', [
            'status' => 'SERVING',
            'timestamp' => time(),
            'stats' => $this->stats
        ]);
    }

    /**
     * Helper method to check if object has property
     */
    private function hasProperty(object $obj, string $property): bool
    {
        return property_exists($obj, $property) || method_exists($obj, 'get' . ucfirst($property));
    }

    /**
     * Helper method to get property value
     */
    private function getProperty(object $obj, string $property): mixed
    {
        if (property_exists($obj, $property)) {
            return $obj->{$property};
        }
        
        $method = 'get' . ucfirst($property);
        if (method_exists($obj, $method)) {
            return $obj->{$method}();
        }
        
        return null;
    }

    /**
     * Helper method to create response object
     */
    private function createResponse(string $type, array $data): object
    {
        // In a real implementation, this would create actual protobuf message
        // For now, create a simple object
        return (object) array_merge(['_type' => $type], $data);
    }

    /**
     * Helper method to serialize object for logging
     */
    private function serializeForLogging(object $obj): array
    {
        // Safe serialization for logging
        try {
            return [
                'type' => get_class($obj),
                'properties' => get_object_vars($obj)
            ];
        } catch (\Throwable $e) {
            return [
                'type' => get_class($obj),
                'error' => 'Failed to serialize: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update response time statistics
     */
    private function updateResponseTime(float $startTime): void
    {
        $responseTime = microtime(true) - $startTime;
        $alpha = 0.1; // Exponential moving average factor
        $this->stats['avg_response_time'] = ($alpha * $responseTime) + 
            ((1 - $alpha) * $this->stats['avg_response_time']);
    }

    /**
     * Get service statistics
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Reset service statistics
     */
    public function resetStats(): void
    {
        $this->stats = [
            'requests_total' => 0,
            'requests_success' => 0,
            'requests_error' => 0,
            'avg_response_time' => 0.0
        ];
    }
}