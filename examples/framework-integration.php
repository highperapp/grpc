<?php

declare(strict_types=1);

/**
 * Example: Framework Integration
 * 
 * This example demonstrates how to integrate the gRPC library
 * with any framework using the service provider pattern.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use HighPerApp\HighPer\GRPC\ServiceProvider\GrpcServiceProvider;
use HighPerApp\HighPer\GRPC\Examples\GreeterService;
use Psr\Container\ContainerInterface;

// Mock container implementation (replace with your framework's container)
class MockContainer implements ContainerInterface
{
    private array $services = [];
    private array $instances = [];

    public function set(string $id, $value): void
    {
        $this->services[$id] = $value;
    }

    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (isset($this->services[$id])) {
            $service = $this->services[$id];
            
            if (is_callable($service)) {
                $instance = $service();
                $this->instances[$id] = $instance;
                return $instance;
            }
            
            return $service;
        }

        if (class_exists($id)) {
            $instance = new $id();
            $this->instances[$id] = $instance;
            return $instance;
        }

        throw new \Exception("Service not found: {$id}");
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]) || 
               isset($this->instances[$id]) || 
               class_exists($id);
    }
}

// Usage example
function main(): void
{
    echo "=== Framework Integration Example ===\n\n";

    // 1. Create container (your framework's container)
    $container = new MockContainer();

    // 2. Configure gRPC
    $grpcConfig = [
        'host' => '127.0.0.1',
        'port' => 9090,
        'worker_processes' => 1,
        'engine' => [
            'rust_acceleration' => false,
            'fallback_to_php' => true
        ],
        'circuit_breaker' => [
            'enabled' => true,
            'failure_threshold' => 5
        ],
        'retry' => [
            'enabled' => true,
            'max_attempts' => 3
        ]
    ];

    // 3. Create and register service provider
    $grpcProvider = new GrpcServiceProvider($container, $grpcConfig);
    $grpcProvider->register();
    $grpcProvider->boot();

    echo "✓ gRPC Service Provider registered\n";

    // 4. Register gRPC services
    $grpcProvider->registerService(GreeterService::class);
    echo "✓ GreeterService registered\n";

    // 5. Auto-discover services from directory (optional)
    // $grpcProvider->discoverServices(__DIR__ . '/services');

    // 6. Get server instance
    $server = $grpcProvider->getServer();
    
    echo "✓ gRPC Server created\n";
    echo "  Host: {$grpcConfig['host']}\n";
    echo "  Port: {$grpcConfig['port']}\n";
    echo "  Services: " . count($server->getServices()) . "\n";

    // 7. Server info
    $info = $server->getInfo();
    echo "\n=== Server Info ===\n";
    echo "Version: {$info['version']}\n";
    echo "PHP Version: {$info['php_version']}\n";
    echo "Workers: {$info['workers']}\n";
    echo "Services: {$info['services']}\n";

    // 8. Validate configuration
    $errors = $grpcProvider->validateConfig();
    if (empty($errors)) {
        echo "✓ Configuration is valid\n";
    } else {
        echo "✗ Configuration errors:\n";
        foreach ($errors as $error) {
            echo "  - {$error}\n";
        }
    }

    // 9. Get configuration schema
    $schema = $grpcProvider->getConfigSchema();
    echo "\n=== Configuration Options ===\n";
    foreach ($schema as $key => $config) {
        if (is_array($config) && isset($config['description'])) {
            echo "- {$key}: {$config['description']}\n";
        } else {
            echo "- {$key}: (nested configuration)\n";
        }
    }

    echo "\n=== Integration Complete ===\n";
    echo "To start the server: \$server->run()\n";
    echo "To use in your framework:\n";
    echo "1. Add this library to composer.json\n";
    echo "2. Register GrpcServiceProvider in your framework\n";
    echo "3. Register your gRPC services\n";
    echo "4. Start the server\n";
}

// Run the example
main();