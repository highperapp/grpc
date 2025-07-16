<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\GRPC\ServiceProvider;

use HighPerApp\HighPer\GRPC\GrpcServer;
use HighPerApp\HighPer\GRPC\GrpcServerFactory;
use HighPerApp\HighPer\GRPC\Engines\HybridEngine;
use HighPerApp\HighPer\GRPC\Protocol\GrpcProtocolHandler;
use HighPerApp\HighPer\GRPC\Reliability\GrpcCircuitBreaker;
use HighPerApp\HighPer\GRPC\Reliability\GrpcRetryHandler;
use HighPerApp\HighPer\GRPC\Serialization\ProtobufSerializer;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * gRPC Service Provider
 * 
 * Generic service provider that can be used with any PHP framework
 * that supports PSR-11 container interface. Provides dependency injection
 * for all gRPC components.
 */
class GrpcServiceProvider
{
    private ContainerInterface $container;
    private array $config;

    public function __construct(ContainerInterface $container, array $config = [])
    {
        $this->container = $container;
        $this->config = $config;
    }

    /**
     * Register gRPC services in the container
     */
    public function register(): void
    {
        // Register factory
        $this->container->set(GrpcServerFactory::class, function () {
            return new GrpcServerFactory(
                $this->config,
                $this->container->has(LoggerInterface::class) 
                    ? $this->container->get(LoggerInterface::class) 
                    : null
            );
        });

        // Register server
        $this->container->set(GrpcServer::class, function () {
            return $this->container->get(GrpcServerFactory::class)->createHighPerformanceServer();
        });

        // Register engine
        $this->container->set(HybridEngine::class, function () {
            return $this->container->get(GrpcServerFactory::class)->createEngine();
        });

        // Register protocol handler
        $this->container->set(GrpcProtocolHandler::class, function () {
            return $this->container->get(GrpcServerFactory::class)->createProtocolHandler(
                $this->container->get(HybridEngine::class)
            );
        });

        // Register circuit breaker
        $this->container->set(GrpcCircuitBreaker::class, function () {
            return $this->container->get(GrpcServerFactory::class)->createCircuitBreaker();
        });

        // Register retry handler
        $this->container->set(GrpcRetryHandler::class, function () {
            return $this->container->get(GrpcServerFactory::class)->createRetryHandler();
        });

        // Register serializer
        $this->container->set(ProtobufSerializer::class, function () {
            return new ProtobufSerializer(
                $this->config['serialization'] ?? [],
                $this->container->has(LoggerInterface::class) 
                    ? $this->container->get(LoggerInterface::class) 
                    : null
            );
        });
    }

    /**
     * Boot gRPC services (optional framework-specific setup)
     */
    public function boot(): void
    {
        // Framework-specific setup can be done here by extending this class
        // or by using the provided hooks
    }

    /**
     * Get gRPC server instance
     */
    public function getServer(): GrpcServer
    {
        return $this->container->get(GrpcServer::class);
    }

    /**
     * Get gRPC server factory
     */
    public function getFactory(): GrpcServerFactory
    {
        return $this->container->get(GrpcServerFactory::class);
    }

    /**
     * Register a gRPC service
     */
    public function registerService(string $serviceClass): void
    {
        $service = $this->container->has($serviceClass) 
            ? $this->container->get($serviceClass)
            : new $serviceClass();
            
        $this->getServer()->registerService($service);
    }

    /**
     * Auto-discover and register services from directory
     */
    public function discoverServices(string $directory, string $namespace = ''): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $this->processServiceFile($file->getPathname(), $namespace);
            }
        }
    }

    /**
     * Get service definitions for container
     */
    public function getServiceDefinitions(): array
    {
        return [
            GrpcServerFactory::class => [
                'factory' => function () {
                    return new GrpcServerFactory(
                        $this->config,
                        $this->container->has(LoggerInterface::class) 
                            ? $this->container->get(LoggerInterface::class) 
                            : null
                    );
                },
                'singleton' => true
            ],
            
            GrpcServer::class => [
                'factory' => function () {
                    return $this->container->get(GrpcServerFactory::class)
                        ->createHighPerformanceServer();
                },
                'singleton' => true
            ],
            
            HybridEngine::class => [
                'factory' => function () {
                    return $this->container->get(GrpcServerFactory::class)
                        ->createEngine();
                },
                'singleton' => true
            ],
            
            GrpcProtocolHandler::class => [
                'factory' => function () {
                    return $this->container->get(GrpcServerFactory::class)
                        ->createProtocolHandler(
                            $this->container->get(HybridEngine::class)
                        );
                },
                'singleton' => true
            ],
            
            GrpcCircuitBreaker::class => [
                'factory' => function () {
                    return $this->container->get(GrpcServerFactory::class)
                        ->createCircuitBreaker();
                },
                'singleton' => false
            ],
            
            GrpcRetryHandler::class => [
                'factory' => function () {
                    return $this->container->get(GrpcServerFactory::class)
                        ->createRetryHandler();
                },
                'singleton' => false
            ],
            
            ProtobufSerializer::class => [
                'factory' => function () {
                    return new ProtobufSerializer(
                        $this->config['serialization'] ?? [],
                        $this->container->has(LoggerInterface::class) 
                            ? $this->container->get(LoggerInterface::class) 
                            : null
                    );
                },
                'singleton' => true
            ]
        ];
    }

    /**
     * Process service file for auto-discovery
     */
    private function processServiceFile(string $filePath, string $namespace = ''): void
    {
        $content = file_get_contents($filePath);
        
        // Extract namespace and class name
        if (preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatch) &&
            preg_match('/class\s+(\w+).*implements\s+.*GrpcServiceInterface/', $content, $classMatch)) {
            
            $fileNamespace = $namespaceMatch[1];
            $className = $classMatch[1];
            $fullClassName = $fileNamespace . '\\' . $className;
            
            if (class_exists($fullClassName)) {
                $reflection = new \ReflectionClass($fullClassName);
                
                if ($reflection->implementsInterface(\HighPerApp\HighPer\GRPC\Contracts\GrpcServiceInterface::class)) {
                    $this->registerService($fullClassName);
                }
            }
        }
    }

    /**
     * Get configuration schema
     */
    public function getConfigSchema(): array
    {
        return [
            'host' => [
                'type' => 'string',
                'default' => '0.0.0.0',
                'description' => 'gRPC server host'
            ],
            'port' => [
                'type' => 'integer',
                'default' => 9090,
                'description' => 'gRPC server port'
            ],
            'worker_processes' => [
                'type' => 'integer',
                'default' => 1,
                'description' => 'Number of worker processes'
            ],
            'parallel_workers' => [
                'type' => 'integer',
                'default' => 1,
                'description' => 'Number of parallel workers per process'
            ],
            'engine' => [
                'rust_acceleration' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => 'Enable Rust FFI acceleration'
                ],
                'fallback_to_php' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => 'Fallback to PHP if Rust fails'
                ]
            ],
            'circuit_breaker' => [
                'enabled' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => 'Enable circuit breaker'
                ],
                'failure_threshold' => [
                    'type' => 'integer',
                    'default' => 5,
                    'description' => 'Failure threshold for circuit breaker'
                ]
            ],
            'retry' => [
                'enabled' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => 'Enable retry mechanism'
                ],
                'max_attempts' => [
                    'type' => 'integer',
                    'default' => 3,
                    'description' => 'Maximum retry attempts'
                ]
            ],
            'security' => [
                'tls_enabled' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Enable TLS encryption'
                ],
                'cert_file' => [
                    'type' => 'string',
                    'default' => null,
                    'description' => 'Path to TLS certificate file'
                ],
                'key_file' => [
                    'type' => 'string',
                    'default' => null,
                    'description' => 'Path to TLS private key file'
                ]
            ]
        ];
    }

    /**
     * Validate configuration
     */
    public function validateConfig(): array
    {
        $errors = [];
        
        // Validate required fields
        if (!isset($this->config['host']) || !is_string($this->config['host'])) {
            $errors[] = 'host must be a valid string';
        }
        
        if (!isset($this->config['port']) || !is_int($this->config['port']) || $this->config['port'] < 1 || $this->config['port'] > 65535) {
            $errors[] = 'port must be a valid port number (1-65535)';
        }
        
        // Validate TLS configuration
        if (isset($this->config['security']['tls_enabled']) && $this->config['security']['tls_enabled']) {
            if (empty($this->config['security']['cert_file']) || !file_exists($this->config['security']['cert_file'])) {
                $errors[] = 'security.cert_file must be a valid file path when TLS is enabled';
            }
            
            if (empty($this->config['security']['key_file']) || !file_exists($this->config['security']['key_file'])) {
                $errors[] = 'security.key_file must be a valid file path when TLS is enabled';
            }
        }
        
        return $errors;
    }
}
}