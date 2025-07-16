<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\GRPC\Foundation;

use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Socket\InternetAddress;
use Amp\Parallel\Worker\{WorkerPool, ContextWorkerPool};
use HighPerApp\HighPer\GRPC\Protocol\GrpcProtocolHandler;
use HighPerApp\HighPer\GRPC\Engines\HybridEngine;
use HighPerApp\HighPer\GRPC\Reliability\GrpcCircuitBreaker;
use HighPerApp\HighPer\GRPC\Reliability\GrpcRetryHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * gRPC Worker Process for Hybrid Multi-Process + Async Architecture
 * 
 * Integrates with HighPer framework's worker process architecture
 * Supports AMPHP v3, circuit breaker, retry patterns, and zero-downtime deployment
 */
class GrpcWorkerProcess
{
    private HttpServer $httpServer;
    private Router $router;
    private GrpcProtocolHandler $protocolHandler;
    private HybridEngine $engine;
    private GrpcCircuitBreaker $circuitBreaker;
    private GrpcRetryHandler $retryHandler;
    private WorkerPool $workerPool;
    private LoggerInterface $logger;
    private array $config;
    private array $services = [];
    private array $stats = [
        'worker_id' => null,
        'pid' => null,
        'started_at' => null,
        'requests_total' => 0,
        'requests_success' => 0,
        'requests_error' => 0,
        'streaming_calls' => 0,
        'active_connections' => 0,
        'bytes_received' => 0,
        'bytes_sent' => 0,
        'avg_response_time' => 0.0
    ];

    public function __construct(
        int $workerId,
        string $host = '0.0.0.0',
        int $port = 9090,
        array $config = [],
        ?LoggerInterface $logger = null
    ) {
        $this->config = array_merge([
            'worker_id' => $workerId,
            'max_concurrent_streams' => 1000,
            'max_frame_size' => 16384,
            'connection_window_size' => 1048576, // 1MB
            'stream_window_size' => 65536, // 64KB
            'keepalive_time' => 30,
            'keepalive_timeout' => 5,
            'rust_acceleration' => true,
            'fallback_to_php' => true,
            'compression' => ['gzip', 'deflate'],
            'reflection_enabled' => true,
            'parallel_workers' => max(1, (int) shell_exec('nproc') ?? 1),
            'circuit_breaker' => [
                'enabled' => true,
                'failure_threshold' => 5,
                'timeout_seconds' => 30,
                'retry_delay_ms' => 1000,
            ],
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3,
                'base_delay_ms' => 100,
                'max_delay_ms' => 5000,
                'exponential_base' => 2.0,
            ],
            'zero_downtime' => [
                'enabled' => true,
                'connection_migration' => true,
                'streaming_preservation' => true,
            ]
        ], $config);
        
        $this->logger = $logger ?? new NullLogger();
        $this->stats['worker_id'] = $workerId;
        $this->stats['pid'] = getmypid();
        $this->stats['started_at'] = time();
        
        // Set process title for monitoring
        if (function_exists('cli_set_process_title')) {
            cli_set_process_title("grpc-worker-{$workerId}");
        }
        
        $this->initializeComponents();
        $this->createServer($host, $port);
    }

    private function initializeComponents(): void
    {
        // Initialize hybrid engine (Rust FFI + PHP fallback)
        $this->engine = new HybridEngine($this->config);
        
        // Initialize protocol handler with HighPer patterns
        $this->protocolHandler = new GrpcProtocolHandler(
            $this->engine,
            $this->config,
            $this->logger
        );
        
        // Initialize reliability patterns
        if ($this->config['circuit_breaker']['enabled']) {
            $this->circuitBreaker = new GrpcCircuitBreaker(
                $this->config['circuit_breaker'],
                $this->logger
            );
        }
        
        if ($this->config['retry']['enabled']) {
            $this->retryHandler = new GrpcRetryHandler(
                $this->config['retry'],
                $this->logger
            );
        }
        
        // Initialize AMPHP parallel worker pool
        $this->workerPool = new ContextWorkerPool(
            workerCount: $this->config['parallel_workers']
        );
        
        $this->logger->info('gRPC worker process initialized', [
            'worker_id' => $this->config['worker_id'],
            'pid' => $this->stats['pid'],
            'engine' => get_class($this->engine),
            'circuit_breaker' => $this->config['circuit_breaker']['enabled'],
            'retry' => $this->config['retry']['enabled'],
            'parallel_workers' => $this->config['parallel_workers']
        ]);
    }

    private function createServer(string $host, int $port): void
    {
        $this->router = new Router();
        
        // gRPC service endpoints
        $this->router->addRoute('POST', '/{service}/{method}', 
            [$this, 'handleGrpcRequest']
        );
        
        // Health check endpoint (gRPC standard)
        $this->router->addRoute('POST', '/grpc.health.v1.Health/Check',
            [$this, 'handleHealthCheck']
        );
        
        // Worker health endpoint for framework monitoring
        $this->router->addRoute('GET', '/worker/health',
            [$this, 'handleWorkerHealth']
        );
        
        // Reflection endpoint (if enabled)
        if ($this->config['reflection_enabled']) {
            $this->router->addRoute('POST', '/grpc.reflection.v1alpha.ServerReflection/ServerReflectionInfo',
                [$this, 'handleReflection']
            );
        }
        
        // Zero-downtime deployment endpoint
        if ($this->config['zero_downtime']['enabled']) {
            $this->router->addRoute('GET', '/worker/connections',
                [$this, 'handleConnectionState']
            );
        }
        
        $this->httpServer = new HttpServer(
            [new InternetAddress($host, $port)],
            $this->router,
            $this->logger
        );
    }

    public function registerService(string $serviceName, object $service): self
    {
        $this->services[$serviceName] = $service;
        
        $this->logger->info('gRPC service registered', [
            'worker_id' => $this->config['worker_id'],
            'service' => $serviceName,
            'methods' => method_exists($service, 'getMethods') ? $service->getMethods() : []
        ]);
        
        return $this;
    }

    public function handleGrpcRequest(Request $request): Response
    {
        $startTime = microtime(true);
        $this->stats['requests_total']++;
        $this->stats['active_connections']++;
        
        try {
            // Extract service and method from path
            $path = $request->getUri()->getPath();
            if (!preg_match('#^/([^/]+)/([^/]+)$#', $path, $matches)) {
                throw new \InvalidArgumentException('Invalid gRPC path format');
            }
            
            $serviceName = $matches[1];
            $methodName = $matches[2];
            
            // Check if service is registered
            if (!isset($this->services[$serviceName])) {
                throw new \RuntimeException("Service not found: {$serviceName}");
            }
            
            // Get request body
            $body = yield $request->getBody()->buffer();
            $this->stats['bytes_received'] += strlen($body);
            
            // Process with reliability patterns
            $response = yield $this->processWithReliability(
                $serviceName,
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
                'worker_id' => $this->config['worker_id'],
                'error' => $e->getMessage(),
                'path' => $request->getUri()->getPath()
            ]);
            
            return $this->createErrorResponse($e);
        } finally {
            $this->stats['active_connections']--;
            $executionTime = microtime(true) - $startTime;
            $this->updateAverageResponseTime($executionTime);
        }
    }

    private function processWithReliability(
        string $serviceName,
        string $methodName,
        string $body,
        array $headers
    ): mixed {
        $operation = function() use ($serviceName, $methodName, $body, $headers) {
            // Use parallel worker for CPU-intensive processing
            $task = new GrpcProcessingTask(
                $this->services[$serviceName],
                $methodName,
                $body,
                $headers
            );
            
            $future = $this->workerPool->submit($task);
            return yield $future->await();
        };
        
        // Apply circuit breaker if enabled
        if ($this->config['circuit_breaker']['enabled']) {
            $operation = function() use ($operation) {
                return $this->circuitBreaker->call($operation);
            };
        }
        
        // Apply retry if enabled
        if ($this->config['retry']['enabled']) {
            $operation = function() use ($operation) {
                return $this->retryHandler->call($operation);
            };
        }
        
        return yield $operation();
    }

    public function handleHealthCheck(Request $request): Response
    {
        // gRPC health check protocol
        $healthStatus = [
            'status' => 'SERVING',
            'worker_id' => $this->config['worker_id'],
            'services' => array_keys($this->services)
        ];
        
        return new Response(
            Response::STATUS_OK,
            [
                'content-type' => 'application/grpc+proto',
                'grpc-status' => '0'
            ],
            $this->protocolHandler->serializeHealthResponse($healthStatus)
        );
    }

    public function handleWorkerHealth(Request $request): Response
    {
        // Worker health for framework monitoring
        $health = [
            'status' => 'healthy',
            'worker_id' => $this->config['worker_id'],
            'pid' => $this->stats['pid'],
            'stats' => $this->getStats(),
            'services' => array_keys($this->services),
            'circuit_breaker' => $this->config['circuit_breaker']['enabled'] ? 
                $this->circuitBreaker->getStats() : null,
            'worker_pool' => [
                'active_workers' => $this->workerPool->getWorkerCount(),
                'idle_workers' => $this->workerPool->getIdleWorkerCount(),
                'pending_tasks' => $this->workerPool->getPendingTaskCount()
            ]
        ];
        
        return new Response(
            Response::STATUS_OK,
            ['content-type' => 'application/json'],
            json_encode($health)
        );
    }

    public function handleConnectionState(Request $request): Response
    {
        // Zero-downtime deployment: return connection state
        $connectionState = [
            'worker_id' => $this->config['worker_id'],
            'active_connections' => $this->stats['active_connections'],
            'streaming_calls' => $this->stats['streaming_calls'],
            'can_migrate' => $this->config['zero_downtime']['connection_migration'],
            'connection_metadata' => $this->getConnectionMetadata()
        ];
        
        return new Response(
            Response::STATUS_OK,
            ['content-type' => 'application/json'],
            json_encode($connectionState)
        );
    }

    private function createErrorResponse(\Throwable $e): Response
    {
        $grpcStatus = $this->mapExceptionToGrpcStatus($e);
        
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

    private function mapExceptionToGrpcStatus(\Throwable $e): int
    {
        return match (true) {
            $e instanceof \InvalidArgumentException => 3, // INVALID_ARGUMENT
            $e instanceof \RuntimeException => 13, // INTERNAL
            str_contains($e->getMessage(), 'not found') => 5, // NOT_FOUND
            str_contains($e->getMessage(), 'timeout') => 4, // DEADLINE_EXCEEDED
            default => 2 // UNKNOWN
        };
    }

    public function run(): void
    {
        $this->logger->info('Starting gRPC worker process', [
            'worker_id' => $this->config['worker_id'],
            'pid' => $this->stats['pid'],
            'address' => $this->httpServer->getServers()[0]->getAddress(),
            'services' => array_keys($this->services)
        ]);
        
        yield $this->httpServer->start();
        
        $this->logger->info('gRPC worker process started successfully', [
            'worker_id' => $this->config['worker_id']
        ]);
    }

    public function stop(): void
    {
        $this->logger->info('Stopping gRPC worker process', [
            'worker_id' => $this->config['worker_id']
        ]);
        
        // Graceful shutdown
        yield $this->httpServer->stop();
        yield $this->workerPool->shutdown();
        
        $this->logger->info('gRPC worker process stopped', [
            'worker_id' => $this->config['worker_id']
        ]);
    }

    public function getStats(): array
    {
        return array_merge($this->stats, [
            'uptime' => time() - $this->stats['started_at'],
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'services_count' => count($this->services),
            'worker_pool_stats' => [
                'active_workers' => $this->workerPool->getWorkerCount(),
                'idle_workers' => $this->workerPool->getIdleWorkerCount(),
                'pending_tasks' => $this->workerPool->getPendingTaskCount()
            ]
        ]);
    }

    private function updateAverageResponseTime(float $executionTime): void
    {
        $alpha = 0.1; // Exponential moving average factor
        $this->stats['avg_response_time'] = ($alpha * $executionTime) + 
            ((1 - $alpha) * $this->stats['avg_response_time']);
    }

    private function getConnectionMetadata(): array
    {
        // Connection metadata for zero-downtime deployment
        return [
            'http2_connections' => [], // Would be populated with actual connection data
            'streaming_rpcs' => [],    // Would be populated with streaming call data
            'metadata' => []           // Would be populated with connection metadata
        ];
    }
}