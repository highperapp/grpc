<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\GRPC;

use HighPerApp\HighPer\GRPC\GrpcServer;
use HighPerApp\HighPer\GRPC\Foundation\GrpcWorkerProcess;
use HighPerApp\HighPer\GRPC\Contracts\GrpcServiceInterface;
use HighPerApp\HighPer\GRPC\Engines\HybridEngine;
use HighPerApp\HighPer\GRPC\Protocol\GrpcProtocolHandler;
use HighPerApp\HighPer\GRPC\Reliability\GrpcCircuitBreaker;
use HighPerApp\HighPer\GRPC\Reliability\GrpcRetryHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * gRPC Server Factory for Standalone Usage
 * 
 * Provides a simple factory interface for creating gRPC servers
 * that can be used in any PHP project without HighPer framework dependency.
 */
class GrpcServerFactory
{
    private array $defaultConfig = [
        'host' => '0.0.0.0',
        'port' => 9090,
        'worker_processes' => 1,
        'parallel_workers' => 1,
        'max_message_size' => 16 * 1024 * 1024,
        'compression_enabled' => true,
        'streaming_enabled' => true,
        'timeout_seconds' => 30,
        'engine' => [
            'rust_acceleration' => false, // Disabled by default for standalone
            'fallback_to_php' => true,
            'optimization_level' => 'balanced'
        ],
        'circuit_breaker' => [
            'enabled' => true,
            'failure_threshold' => 5,
            'success_threshold' => 3,
            'timeout_seconds' => 60
        ],
        'retry' => [
            'enabled' => true,
            'max_attempts' => 3,
            'base_delay_ms' => 100,
            'max_delay_ms' => 30000
        ],
        'security' => [
            'tls_enabled' => false,
            'cert_file' => null,
            'key_file' => null,
            'ca_file' => null
        ]
    ];

    private LoggerInterface $logger;
    private array $config;

    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->config = array_merge($this->defaultConfig, $config);
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Create a simple gRPC server (single process)
     */
    public function createSimpleServer(): GrpcServer
    {
        $this->config['worker_processes'] = 1;
        $this->config['parallel_workers'] = 1;
        
        return new GrpcServer($this->config, $this->logger);
    }

    /**
     * Create a high-performance gRPC server (multi-process)
     */
    public function createHighPerformanceServer(): GrpcServer
    {
        $this->config['worker_processes'] = max(1, (int) shell_exec('nproc') ?? 1);
        $this->config['parallel_workers'] = max(1, (int) shell_exec('nproc') ?? 1);
        $this->config['engine']['rust_acceleration'] = true;
        
        return new GrpcServer($this->config, $this->logger);
    }

    /**
     * Create a secure gRPC server with TLS
     */
    public function createSecureServer(string $certFile, string $keyFile, ?string $caFile = null): GrpcServer
    {
        $this->config['security'] = [
            'tls_enabled' => true,
            'cert_file' => $certFile,
            'key_file' => $keyFile,
            'ca_file' => $caFile
        ];
        
        return new GrpcServer($this->config, $this->logger);
    }

    /**
     * Create a development server with debug features
     */
    public function createDevelopmentServer(): GrpcServer
    {
        $this->config['worker_processes'] = 1;
        $this->config['parallel_workers'] = 1;
        $this->config['engine']['rust_acceleration'] = false;
        $this->config['circuit_breaker']['enabled'] = false;
        $this->config['retry']['enabled'] = false;
        
        return new GrpcServer($this->config, $this->logger);
    }

    /**
     * Create a worker process for the server
     */
    public function createWorkerProcess(int $workerId, array $overrideConfig = []): GrpcWorkerProcess
    {
        $workerConfig = array_merge($this->config, $overrideConfig);
        $workerConfig['worker_id'] = $workerId;
        
        return new GrpcWorkerProcess(
            $workerId,
            $workerConfig['host'],
            $workerConfig['port'],
            $workerConfig,
            $this->logger
        );
    }

    /**
     * Create engine instance
     */
    public function createEngine(array $config = []): HybridEngine
    {
        $engineConfig = array_merge($this->config['engine'], $config);
        return new HybridEngine($engineConfig, $this->logger);
    }

    /**
     * Create protocol handler
     */
    public function createProtocolHandler(HybridEngine $engine, array $config = []): GrpcProtocolHandler
    {
        $handlerConfig = array_merge($this->config, $config);
        return new GrpcProtocolHandler($engine, $handlerConfig, $this->logger);
    }

    /**
     * Create circuit breaker
     */
    public function createCircuitBreaker(array $config = []): GrpcCircuitBreaker
    {
        $breakerConfig = array_merge($this->config['circuit_breaker'], $config);
        return new GrpcCircuitBreaker($breakerConfig, $this->logger);
    }

    /**
     * Create retry handler
     */
    public function createRetryHandler(array $config = []): GrpcRetryHandler
    {
        $retryConfig = array_merge($this->config['retry'], $config);
        return new GrpcRetryHandler($retryConfig, $this->logger);
    }

    /**
     * Get default configuration
     */
    public function getDefaultConfig(): array
    {
        return $this->defaultConfig;
    }

    /**
     * Get current configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Update configuration
     */
    public function updateConfig(array $config): self
    {
        $this->config = array_merge($this->config, $config);
        return $this;
    }

    /**
     * Set logger
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }
}