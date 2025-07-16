<?php

declare(strict_types=1);

/**
 * Example gRPC Server - Standalone Usage
 * 
 * Demonstrates how to set up a gRPC server as a standalone application
 * using the GrpcServerFactory and worker processes.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use HighPerApp\HighPer\GRPC\GrpcServerFactory;
use HighPerApp\HighPer\GRPC\Examples\GreeterService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

// Configuration
$config = [
    'host' => '0.0.0.0',
    'port' => 9090,
    'worker_processes' => 4,
    'parallel_workers' => max(1, (int) shell_exec('nproc') ?? 1),
    'circuit_breaker' => [
        'enabled' => true,
        'failure_threshold' => 5,
        'timeout_seconds' => 60
    ],
    'retry' => [
        'enabled' => true,
        'max_attempts' => 3,
        'base_delay_ms' => 100
    ],
    'engine' => [
        'rust_acceleration' => true,
        'fallback_to_php' => true
    ],
    'max_message_size' => 16 * 1024 * 1024, // 16MB
    'compression_enabled' => true,
    'streaming_enabled' => true,
    'timeout_seconds' => 30
];

// Logger (in production, use proper logger)
$logger = new NullLogger();

echo "Starting gRPC server on {$config['host']}:{$config['port']}\n";
echo "Worker processes: {$config['worker_processes']}\n";
echo "Parallel workers per process: {$config['parallel_workers']}\n";

// Create server factory
$factory = new GrpcServerFactory($config, $logger);

// Create server
$server = $factory->createHighPerformanceServer();

// Register services
$greeterService = new GreeterService($logger);
$server->registerService($greeterService);

// Signal handling
$shutdown = false;
pcntl_signal(SIGTERM, function() use (&$shutdown, $server) {
    $shutdown = true;
    $server->handleShutdown();
});
pcntl_signal(SIGINT, function() use (&$shutdown, $server) {
    $shutdown = true;
    $server->handleShutdown();
});

echo "gRPC server started successfully\n";
echo "Services registered:\n";
foreach ($server->getServices() as $serviceName => $service) {
    echo "  - {$serviceName} (v{$service->getServiceVersion()})\n";
    foreach ($service->getMethods() as $methodName => $methodInfo) {
        echo "    - {$methodName}\n";
    }
}

// Start server
$server->start();

// Main server loop
while (!$shutdown) {
    pcntl_signal_dispatch();
    
    // Check server health
    if (!$server->isHealthy()) {
        echo "Server unhealthy, attempting restart...\n";
        $server->stop();
        $server->start();
    }
    
    // Print statistics every 30 seconds
    static $lastStats = 0;
    if (time() - $lastStats > 30) {
        echo "\n=== Server Statistics ===\n";
        $stats = $server->getStats();
        echo "Total requests: {$stats['total_requests']}\n";
        echo "Total errors: {$stats['total_errors']}\n";
        echo "Uptime: {$stats['uptime']} seconds\n";
        echo "=========================\n\n";
        $lastStats = time();
    }
    
    sleep(1);
}

// Graceful shutdown
echo "\nShutting down gRPC server...\n";
$server->stop();
echo "gRPC server stopped\n";