<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\GRPC\Parallel;

use Amp\Parallel\Worker\Task;
use Amp\Parallel\Worker\Environment;
use HighPerApp\HighPer\GRPC\Engines\HybridEngine;
use HighPerApp\HighPer\GRPC\Protocol\GrpcProtocolHandler;
use HighPerApp\HighPer\GRPC\Exceptions\GrpcException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * gRPC Processing Task for AMPHP Parallel
 * 
 * Integrates gRPC processing with amphp/parallel for CPU-intensive
 * operations in the HighPer framework's Hybrid Multi-Process + Async architecture.
 */
class GrpcProcessingTask implements Task
{
    private string $serviceClass;
    private string $methodName;
    private string $requestData;
    private array $headers;
    private array $config;
    private float $startTime;

    public function __construct(
        string $serviceClass,
        string $methodName,
        string $requestData,
        array $headers = [],
        array $config = []
    ) {
        $this->serviceClass = $serviceClass;
        $this->methodName = $methodName;
        $this->requestData = $requestData;
        $this->headers = $headers;
        $this->config = array_merge([
            'timeout' => 30,
            'memory_limit' => '256M',
            'enable_logging' => false,
            'enable_metrics' => true,
            'rust_acceleration' => true,
            'compression_enabled' => true,
            'max_message_size' => 16 * 1024 * 1024
        ], $config);
        $this->startTime = microtime(true);
    }

    /**
     * Execute the task in parallel worker
     */
    public function run(Environment $environment): array
    {
        $taskStartTime = microtime(true);
        
        try {
            // Set memory limit for this task
            if ($this->config['memory_limit']) {
                ini_set('memory_limit', $this->config['memory_limit']);
            }
            
            // Initialize logger
            $logger = $this->config['enable_logging'] ? 
                new NullLogger() : // In a real implementation, would use proper logger
                new NullLogger();
            
            // Initialize gRPC components
            $engine = new HybridEngine($this->config, $logger);
            $protocolHandler = new GrpcProtocolHandler($engine, $this->config, $logger);
            
            // Create service instance
            $service = $this->createServiceInstance();
            
            // Process the gRPC request
            $response = $protocolHandler->processRequest(
                $service,
                $this->methodName,
                $this->requestData,
                $this->headers
            );
            
            $processingTime = microtime(true) - $taskStartTime;
            $totalTime = microtime(true) - $this->startTime;
            
            // Prepare response with metrics
            $result = [
                'success' => true,
                'response' => $response,
                'metrics' => [
                    'processing_time' => $processingTime,
                    'total_time' => $totalTime,
                    'memory_peak' => memory_get_peak_usage(true),
                    'memory_current' => memory_get_usage(true),
                    'engine_type' => $engine->getStats()['engine_type'] ?? 'unknown',
                    'worker_id' => $environment->get('WORKER_ID') ?? 'unknown'
                ]
            ];
            
            // Add engine statistics if enabled
            if ($this->config['enable_metrics']) {
                $result['metrics']['engine_stats'] = $engine->getStats();
                $result['metrics']['protocol_stats'] = $protocolHandler->getStats();
            }
            
            return $result;
            
        } catch (\Throwable $e) {
            $processingTime = microtime(true) - $taskStartTime;
            $totalTime = microtime(true) - $this->startTime;
            
            // Return error response
            return [
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'type' => get_class($e),
                    'grpc_status' => $e instanceof GrpcException ? $e->getGrpcStatus() : 13,
                    'trace' => $e->getTraceAsString()
                ],
                'metrics' => [
                    'processing_time' => $processingTime,
                    'total_time' => $totalTime,
                    'memory_peak' => memory_get_peak_usage(true),
                    'memory_current' => memory_get_usage(true),
                    'worker_id' => $environment->get('WORKER_ID') ?? 'unknown'
                ]
            ];
        }
    }

    /**
     * Create service instance
     */
    private function createServiceInstance(): object
    {
        if (!class_exists($this->serviceClass)) {
            throw new GrpcException("Service class not found: {$this->serviceClass}");
        }
        
        try {
            $service = new $this->serviceClass();
            
            // Validate service has the required method
            if (!method_exists($service, $this->methodName)) {
                throw new GrpcException("Method {$this->methodName} not found in service {$this->serviceClass}");
            }
            
            return $service;
            
        } catch (\Throwable $e) {
            throw new GrpcException("Failed to create service instance: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Get task identifier
     */
    public function getId(): string
    {
        return hash('xxh3', $this->serviceClass . $this->methodName . $this->requestData);
    }

    /**
     * Get task metadata
     */
    public function getMetadata(): array
    {
        return [
            'service_class' => $this->serviceClass,
            'method_name' => $this->methodName,
            'request_size' => strlen($this->requestData),
            'header_count' => count($this->headers),
            'config' => $this->config,
            'start_time' => $this->startTime
        ];
    }

    /**
     * Estimate task complexity (for load balancing)
     */
    public function getComplexity(): int
    {
        // Simple heuristic based on request size and method
        $baseComplexity = 1;
        $sizeComplexity = intval(strlen($this->requestData) / 1024); // KB
        $methodComplexity = match (true) {
            str_contains($this->methodName, 'batch') => 3,
            str_contains($this->methodName, 'stream') => 2,
            default => 1
        };
        
        return $baseComplexity + $sizeComplexity + $methodComplexity;
    }

    /**
     * Check if task can be cached
     */
    public function isCacheable(): bool
    {
        // Only cache idempotent operations
        return in_array($this->methodName, [
            'get',
            'list',
            'search',
            'query',
            'describe',
            'health'
        ]);
    }

    /**
     * Get cache key for this task
     */
    public function getCacheKey(): string
    {
        if (!$this->isCacheable()) {
            return '';
        }
        
        return hash('xxh3', $this->serviceClass . $this->methodName . $this->requestData);
    }

    /**
     * Get task timeout
     */
    public function getTimeout(): int
    {
        return $this->config['timeout'];
    }

    /**
     * Check if task should be retried on failure
     */
    public function shouldRetry(\Throwable $e): bool
    {
        // Only retry on specific error types
        if ($e instanceof GrpcException) {
            return $e->isRetriable();
        }
        
        // Retry on timeout and resource issues
        return str_contains($e->getMessage(), 'timeout') ||
               str_contains($e->getMessage(), 'memory') ||
               str_contains($e->getMessage(), 'resource');
    }

    /**
     * Get retry delay in seconds
     */
    public function getRetryDelay(): float
    {
        return 0.1; // 100ms base delay
    }

    /**
     * Get maximum retry attempts
     */
    public function getMaxRetries(): int
    {
        return 3;
    }

    /**
     * Serialize task for transmission
     */
    public function __serialize(): array
    {
        return [
            'serviceClass' => $this->serviceClass,
            'methodName' => $this->methodName,
            'requestData' => $this->requestData,
            'headers' => $this->headers,
            'config' => $this->config,
            'startTime' => $this->startTime
        ];
    }

    /**
     * Unserialize task from transmission
     */
    public function __unserialize(array $data): void
    {
        $this->serviceClass = $data['serviceClass'];
        $this->methodName = $data['methodName'];
        $this->requestData = $data['requestData'];
        $this->headers = $data['headers'];
        $this->config = $data['config'];
        $this->startTime = $data['startTime'];
    }
}