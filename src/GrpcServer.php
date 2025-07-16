<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\GRPC;

use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Status;
use Amp\Socket;
use Amp\Socket\InternetAddress;
use HighPerApp\HighPer\GRPC\Contracts\GrpcServiceInterface;
use HighPerApp\HighPer\GRPC\Engines\RustFFIEngine;
use HighPerApp\HighPer\GRPC\Engines\PurePHPEngine;
use HighPerApp\HighPer\GRPC\Exceptions\GrpcException;
use HighPerApp\HighPer\GRPC\Protocol\GrpcProtocolHandler;
use HighPerApp\HighPer\GRPC\Serialization\ProtobufSerializer;
use Google\Protobuf\Internal\Message;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class GrpcServer
{
    private ?HttpServer $httpServer = null;
    private GrpcProtocolHandler $protocolHandler;
    private RustFFIEngine|PurePHPEngine $engine;
    private ProtobufSerializer $serializer;
    private LoggerInterface $logger;
    private array $config;
    private array $services = [];
    private bool $running = false;
    private array $stats = [
        'requests_total' => 0,
        'requests_success' => 0,
        'requests_error' => 0,
        'bytes_received' => 0,
        'bytes_sent' => 0,
        'start_time' => null,
        'uptime' => 0
    ];

    public function __construct(
        string $host = '0.0.0.0',
        int $port = 9090,
        array $config = [],
        ?LoggerInterface $logger = null
    ) {
        $this->config = array_merge([
            'max_concurrent_streams' => 1000,
            'max_frame_size' => 16384,
            'connection_window_size' => 1048576, // 1MB
            'stream_window_size' => 65536, // 64KB
            'keepalive_time' => 30,
            'keepalive_timeout' => 5,
            'rust_acceleration' => true,
            'fallback_to_php' => true,
            'compression' => ['gzip', 'deflate'],
            'reflection_enabled' => true
        ], $config);
        
        $this->logger = $logger ?? new NullLogger();
        $this->stats['start_time'] = time();
        
        $this->initializeEngine();
        $this->initializeSerializer();
        $this->initializeProtocolHandler();
        $this->createServer($host, $port);
    }

    private function initializeEngine(): void
    {
        if ($this->config['rust_acceleration'] && RustFFIEngine::isAvailable()) {
            try {
                $this->engine = new RustFFIEngine($this->config);
                $this->logger->info('gRPC Server initialized with Rust FFI acceleration');
            } catch (\Throwable $e) {
                if ($this->config['fallback_to_php']) {
                    $this->engine = new PurePHPEngine($this->config);
                    $this->logger->warning('Rust FFI failed, falling back to pure PHP', ['error' => $e->getMessage()]);
                } else {
                    throw new GrpcException('Rust FFI initialization failed and fallback disabled', 0, $e);
                }
            }
        } else {
            $this->engine = new PurePHPEngine($this->config);
            $this->logger->info('gRPC Server initialized with pure PHP engine');
        }
    }

    private function initializeSerializer(): void
    {
        $this->serializer = new ProtobufSerializer();
    }

    private function initializeProtocolHandler(): void
    {
        $this->protocolHandler = new GrpcProtocolHandler(
            $this->engine,
            $this->config,
            $this->logger
        );
    }

    private function createServer(string $host, int $port): void
    {
        // For now, just create a placeholder - the actual server will be handled by the framework
        $this->httpServer = null;
    }

    public function registerService(GrpcServiceInterface $service): self
    {
        $serviceName = $service->getServiceName();
        $this->services[$serviceName] = $service;
        
        $this->logger->info('gRPC service registered', [
            'service' => $serviceName,
            'methods' => $service->getMethods()
        ]);
        
        return $this;
    }

    public function handleGRPCRequest(Request $request): \Generator
    {
        $this->stats['requests_total']++;
        
        try {
            // Extract service and method from path
            $path = $request->getUri()->getPath();
            if (!preg_match('#^/([^/]+)/([^/]+)$#', $path, $matches)) {
                throw new GrpcException('Invalid gRPC path format');
            }
            
            $serviceName = $matches[1];
            $methodName = $matches[2];
            
            // Check if service is registered
            if (!isset($this->services[$serviceName])) {
                throw new GrpcException("Service not found: {$serviceName}");
            }
            
            $service = $this->services[$serviceName];
            
            // Validate content type
            $contentType = $request->getHeader('content-type');
            if (!str_starts_with($contentType, 'application/grpc')) {
                throw new GrpcException('Invalid content type for gRPC request');
            }
            
            // Get request body
            $body = yield $request->getBody()->buffer();
            $this->stats['bytes_received'] += strlen($body);
            
            // Process the gRPC request
            $response = yield $this->protocolHandler->processRequest(
                $service,
                $methodName,
                $body,
                $request->getHeaders()
            );
            
            $this->stats['requests_success']++;
            $this->stats['bytes_sent'] += strlen($response['body']);
            
            return new Response(
                Response::STATUS_OK,
                $response['headers'],
                $response['body']
            );
            
        } catch (\Throwable $e) {
            $this->stats['requests_error']++;
            
            $this->logger->error('gRPC request failed', [
                'error' => $e->getMessage(),
                'path' => $request->getUri()->getPath()
            ]);
            
            return $this->createErrorResponse($e);
        }
    }

    public function handleHealthCheck(Request $request): Response
    {
        $health = [
            'status' => 'SERVING',
            'stats' => $this->getStats(),
            'services' => array_keys($this->services)
        ];
        
        return new Response(
            Response::STATUS_OK,
            ['content-type' => 'application/json'],
            json_encode($health)
        );
    }

    public function handleReflection(Request $request): \Generator
    {
        try {
            $body = yield $request->getBody()->buffer();
            
            // Process reflection request
            $reflectionResponse = $this->protocolHandler->processReflection(
                $body,
                $this->services
            );
            
            return new Response(
                Response::STATUS_OK,
                [
                    'content-type' => 'application/grpc+proto',
                    'grpc-status' => '0'
                ],
                $reflectionResponse
            );
            
        } catch (\Throwable $e) {
            $this->logger->error('Reflection request failed', [
                'error' => $e->getMessage()
            ]);
            
            return $this->createErrorResponse($e);
        }
    }

    private function createErrorResponse(\Throwable $e): Response
    {
        $grpcStatus = $this->mapExceptionToGRPCStatus($e);
        
        return new Response(
            Response::STATUS_OK, // gRPC always returns 200 OK
            [
                'content-type' => 'application/grpc+proto',
                'grpc-status' => (string)$grpcStatus,
                'grpc-message' => $e->getMessage()
            ],
            ''
        );
    }

    private function mapExceptionToGRPCStatus(\Throwable $e): int
    {
        // Map PHP exceptions to gRPC status codes
        return match (true) {
            $e instanceof \InvalidArgumentException => 3, // INVALID_ARGUMENT
            $e instanceof \RuntimeException => 13, // INTERNAL
            str_contains($e->getMessage(), 'not found') => 5, // NOT_FOUND
            str_contains($e->getMessage(), 'timeout') => 4, // DEADLINE_EXCEEDED
            default => 2 // UNKNOWN
        };
    }

    public function run(): \Generator
    {
        $this->logger->info('Starting gRPC server', [
            'engine' => get_class($this->engine),
            'services' => array_keys($this->services)
        ]);
        
        if ($this->httpServer) {
            yield $this->httpServer->start();
        }
        
        $this->logger->info('gRPC server started successfully');
    }

    public function stop(): \Generator
    {
        $this->logger->info('Stopping gRPC server');
        
        if ($this->httpServer) {
            yield $this->httpServer->stop();
        }
        
        $this->logger->info('gRPC server stopped');
    }

    public function getStats(): array
    {
        return array_merge($this->stats, [
            'uptime' => time() - $this->stats['start_time'],
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'services_count' => count($this->services)
        ]);
    }

    public function getInfo(): array
    {
        return [
            'version' => '1.0.0',
            'php_version' => PHP_VERSION,
            'workers' => $this->config['worker_processes'] ?? 1,
            'services' => count($this->services),
            'engine' => get_class($this->engine),
            'status' => $this->httpServer ? 'initialized' : 'not_initialized'
        ];
    }

    public function getServices(): array
    {
        return array_map(function($service) {
            return [
                'class' => get_class($service),
                'methods' => $service->getMethods()
            ];
        }, $this->services);
    }

    public function enableGRPCWeb(): self
    {
        // Add CORS headers for gRPC-Web
        $this->router->addRoute('OPTIONS', '/{service}/{method}', function(Request $request): Response {
            return new Response(
                Response::STATUS_OK,
                [
                    'access-control-allow-origin' => '*',
                    'access-control-allow-methods' => 'POST, OPTIONS',
                    'access-control-allow-headers' => 'content-type, x-grpc-web, x-user-agent',
                    'access-control-max-age' => '3600'
                ],
                ''
            );
        });
        
        return $this;
    }

    public function addMiddleware(callable $middleware): self
    {
        // Add middleware to the router
        $this->router->stack($middleware);
        return $this;
    }

    public function setCompressionEnabled(bool $enabled): self
    {
        $this->config['compression_enabled'] = $enabled;
        return $this;
    }

    public function setMaxMessageSize(int $size): self
    {
        $this->config['max_message_size'] = $size;
        return $this;
    }

    public function getEngine(): RustFFIEngine|PurePHPEngine
    {
        return $this->engine;
    }

    public function getProtocolHandler(): GrpcProtocolHandler
    {
        return $this->protocolHandler;
    }
}
