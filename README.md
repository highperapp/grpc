# HighPer gRPC

High-Performance gRPC Server with Rust FFI acceleration and pure PHP fallback for ultra-fast RPC communication. Built with Hybrid Multi-Process + Async architecture using AMPHP v3.

## Features

- 🚀 **Rust FFI Acceleration** with pure PHP fallback
- 🔄 **Hybrid Multi-Process + Async Architecture** using AMPHP v3
- 🛡️ **Circuit Breaker & Retry Patterns** for fault tolerance
- 📦 **Framework Integration** via PSR-11 service provider
- 🧪 **Comprehensive Testing** (Unit, Integration, Performance, Concurrency, Reliability)
- 🔧 **Standalone & Framework Usage** supported
- 📊 **High Performance** with parallel processing support

## Requirements

- PHP 8.3+ or PHP 8.4+
- ext-ffi (for Rust acceleration)
- AMPHP v3+
- google/protobuf

## Installation

```bash
composer require highperapp/grpc
```

## Quick Start

### Standalone Usage

```php
<?php
use HighPerApp\HighPer\GRPC\GrpcServerFactory;
use HighPerApp\HighPer\GRPC\Examples\GreeterService;

// Configuration
$config = [
    'host' => '0.0.0.0',
    'port' => 9090,
    'worker_processes' => 4,
    'parallel_workers' => 2,
    'circuit_breaker' => ['enabled' => true],
    'retry' => ['enabled' => true],
    'engine' => ['rust_acceleration' => true]
];

// Create server
$factory = new GrpcServerFactory($config);
$server = $factory->createHighPerformanceServer();

// Register service
$server->registerService(new GreeterService());

// Start server
$server->start();
```

### Framework Integration

```php
<?php
use HighPerApp\HighPer\GRPC\ServiceProvider\GrpcServiceProvider;

// In your framework's service container
$grpcProvider = new GrpcServiceProvider($container, $config);
$grpcProvider->register();
$grpcProvider->boot();

// Register services
$grpcProvider->registerService(YourService::class);

// Get server
$server = $grpcProvider->getServer();
```

## Architecture

- **Hybrid Multi-Process + Async**: Combines process isolation with async I/O
- **AMPHP v3 Integration**: Uses amphp/parallel for CPU-intensive tasks
- **Circuit Breaker Pattern**: Prevents cascade failures
- **Retry Mechanism**: Automatic retry with exponential backoff
- **Dual Engine**: Rust FFI for performance + PHP fallback for compatibility

## Configuration

```php
$config = [
    'host' => '0.0.0.0',
    'port' => 9090,
    'worker_processes' => 4,
    'parallel_workers' => 2,
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
    ]
];
```

## Testing

```bash
# Run all tests
composer test

# Run specific test suites
composer test:unit
composer test:integration
composer test:performance
composer test:concurrency
composer test:reliability

# Code coverage
composer test:coverage

# Code style
composer cs-check
composer cs-fix
```

## Examples

See the `examples/` directory for:
- `server.php` - Standalone server setup
- `client.php` - Client usage
- `framework-integration.php` - Framework integration example
- `GreeterService.php` - Example service implementation

## Performance

- **High Throughput**: Designed for high-concurrency scenarios
- **Low Latency**: Rust FFI acceleration for critical paths
- **Resource Efficient**: Process isolation with shared worker pools
- **Fault Tolerant**: Circuit breaker and retry patterns

## Security

- TLS/HTTPS support (configurable)
- Input validation and sanitization
- Rate limiting capabilities
- Secure by default configuration

## Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License

MIT License - see [LICENSE](LICENSE) file for details.
