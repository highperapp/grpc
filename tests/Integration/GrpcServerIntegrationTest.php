<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\GRPC\Tests\Integration;

use PHPUnit\Framework\TestCase;
use HighPerApp\HighPer\GRPC\GrpcServer;
use HighPerApp\HighPer\GRPC\GrpcServerFactory;
use HighPerApp\HighPer\GRPC\Examples\GreeterService;
use Psr\Log\NullLogger;

/**
 * Integration tests for GrpcServer
 */
class GrpcServerIntegrationTest extends TestCase
{
    private GrpcServer $server;
    private GrpcServerFactory $factory;
    private GreeterService $service;

    protected function setUp(): void
    {
        $this->factory = new GrpcServerFactory([
            'host' => '127.0.0.1',
            'port' => 9091, // Use different port to avoid conflicts
            'worker_processes' => 1,
            'engine' => ['rust_acceleration' => false],
            'circuit_breaker' => ['enabled' => false],
            'retry' => ['enabled' => false]
        ], new NullLogger());
        
        $this->server = $this->factory->createDevelopmentServer();
        $this->service = new GreeterService(new NullLogger());
        $this->server->registerService($this->service);
    }

    protected function tearDown(): void
    {
        if ($this->server->isRunning()) {
            $this->server->stop();
        }
    }

    public function testServerStartAndStop(): void
    {
        $this->assertFalse($this->server->isRunning());
        
        $this->server->start();
        $this->assertTrue($this->server->isRunning());
        
        $this->server->stop();
        $this->assertFalse($this->server->isRunning());
    }

    public function testServiceRegistration(): void
    {
        $services = $this->server->getServices();
        
        $this->assertArrayHasKey('helloworld.Greeter', $services);
        $this->assertInstanceOf(GreeterService::class, $services['helloworld.Greeter']);
    }

    public function testServerConfiguration(): void
    {
        $config = $this->server->getConfig();
        
        $this->assertIsArray($config);
        $this->assertEquals('127.0.0.1', $config['host']);
        $this->assertEquals(9091, $config['port']);
        $this->assertEquals(1, $config['worker_processes']);
    }

    public function testServerStats(): void
    {
        $stats = $this->server->getStats();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_requests', $stats);
        $this->assertArrayHasKey('total_errors', $stats);
        $this->assertArrayHasKey('uptime', $stats);
        $this->assertArrayHasKey('workers', $stats);
        
        $this->assertIsNumeric($stats['total_requests']);
        $this->assertIsNumeric($stats['total_errors']);
        $this->assertIsNumeric($stats['uptime']);
        $this->assertIsArray($stats['workers']);
    }

    public function testServerInfo(): void
    {
        $info = $this->server->getInfo();
        
        $this->assertIsArray($info);
        $this->assertArrayHasKey('version', $info);
        $this->assertArrayHasKey('php_version', $info);
        $this->assertArrayHasKey('host', $info);
        $this->assertArrayHasKey('port', $info);
        $this->assertArrayHasKey('workers', $info);
        $this->assertArrayHasKey('services', $info);
        $this->assertArrayHasKey('running', $info);
        $this->assertArrayHasKey('healthy', $info);
        
        $this->assertEquals('1.0.0', $info['version']);
        $this->assertEquals(PHP_VERSION, $info['php_version']);
        $this->assertEquals('127.0.0.1', $info['host']);
        $this->assertEquals(9091, $info['port']);
        $this->assertEquals(1, $info['workers']);
        $this->assertEquals(1, $info['services']);
        $this->assertIsBool($info['running']);
        $this->assertIsBool($info['healthy']);
    }

    public function testServerHealthCheck(): void
    {
        $this->assertTrue($this->server->isHealthy());
        
        $this->server->start();
        $this->assertTrue($this->server->isHealthy());
        
        $this->server->stop();
        $this->assertFalse($this->server->isHealthy());
    }

    public function testWorkerProcesses(): void
    {
        $workers = $this->server->getWorkers();
        
        $this->assertIsArray($workers);
        $this->assertCount(1, $workers);
        
        foreach ($workers as $worker) {
            $this->assertInstanceOf(
                \HighPerApp\HighPer\GRPC\Foundation\GrpcWorkerProcess::class,
                $worker
            );
        }
    }

    public function testServerWithMultipleServices(): void
    {
        // Create another service
        $service2 = new class extends GreeterService {
            public function getServiceName(): string
            {
                return 'test.TestService';
            }
        };
        
        $this->server->registerService($service2);
        
        $services = $this->server->getServices();
        
        $this->assertCount(2, $services);
        $this->assertArrayHasKey('helloworld.Greeter', $services);
        $this->assertArrayHasKey('test.TestService', $services);
    }

    public function testServerFactoryDifferentConfigurations(): void
    {
        // Test simple server
        $simpleServer = $this->factory->createSimpleServer();
        $this->assertInstanceOf(GrpcServer::class, $simpleServer);
        
        // Test high-performance server
        $hpServer = $this->factory->createHighPerformanceServer();
        $this->assertInstanceOf(GrpcServer::class, $hpServer);
        
        // Test development server
        $devServer = $this->factory->createDevelopmentServer();
        $this->assertInstanceOf(GrpcServer::class, $devServer);
    }

    public function testServerFactoryComponents(): void
    {
        $engine = $this->factory->createEngine();
        $this->assertInstanceOf(
            \HighPerApp\HighPer\GRPC\Engines\HybridEngine::class,
            $engine
        );
        
        $protocolHandler = $this->factory->createProtocolHandler($engine);
        $this->assertInstanceOf(
            \HighPerApp\HighPer\GRPC\Protocol\GrpcProtocolHandler::class,
            $protocolHandler
        );
        
        $circuitBreaker = $this->factory->createCircuitBreaker();
        $this->assertInstanceOf(
            \HighPerApp\HighPer\GRPC\Reliability\GrpcCircuitBreaker::class,
            $circuitBreaker
        );
        
        $retryHandler = $this->factory->createRetryHandler();
        $this->assertInstanceOf(
            \HighPerApp\HighPer\GRPC\Reliability\GrpcRetryHandler::class,
            $retryHandler
        );
    }

    public function testServerFactoryWorkerProcess(): void
    {
        $worker = $this->factory->createWorkerProcess(1);
        
        $this->assertInstanceOf(
            \HighPerApp\HighPer\GRPC\Foundation\GrpcWorkerProcess::class,
            $worker
        );
        
        $this->assertEquals(1, $worker->getWorkerId());
    }

    public function testServerFactoryConfiguration(): void
    {
        $defaultConfig = $this->factory->getDefaultConfig();
        $this->assertIsArray($defaultConfig);
        
        $currentConfig = $this->factory->getConfig();
        $this->assertIsArray($currentConfig);
        
        // Test configuration update
        $this->factory->updateConfig(['test_key' => 'test_value']);
        $updatedConfig = $this->factory->getConfig();
        $this->assertEquals('test_value', $updatedConfig['test_key']);
    }

    public function testServerEngineIntegration(): void
    {
        $engine = $this->server->getEngine();
        
        $this->assertInstanceOf(
            \HighPerApp\HighPer\GRPC\Engines\RustFFIEngine::class,
            $engine
        );
        
        $this->assertTrue($engine->isReady());
    }

    public function testServerProtocolHandlerIntegration(): void
    {
        $handler = $this->server->getProtocolHandler();
        
        $this->assertInstanceOf(
            \HighPerApp\HighPer\GRPC\Protocol\GrpcProtocolHandler::class,
            $handler
        );
        
        $stats = $handler->getStats();
        $this->assertIsArray($stats);
    }

    public function testServerShutdownHandling(): void
    {
        $this->server->start();
        $this->assertTrue($this->server->isRunning());
        
        // Simulate shutdown signal
        $this->server->handleShutdown();
        
        // Server should be marked for shutdown
        $this->assertFalse($this->server->isRunning());
    }

    public function testServerStatsUpdating(): void
    {
        $statsBefore = $this->server->getStats();
        
        // Start server to trigger some activity
        $this->server->start();
        
        // Get stats after starting
        $statsAfter = $this->server->getStats();
        
        // Uptime should have increased
        $this->assertGreaterThanOrEqual($statsBefore['uptime'], $statsAfter['uptime']);
        
        $this->server->stop();
    }

    public function testServerMemoryUsage(): void
    {
        $memoryBefore = memory_get_usage(true);
        
        $this->server->start();
        
        $stats = $this->server->getStats();
        $this->assertGreaterThan($memoryBefore, memory_get_usage(true));
        
        $this->server->stop();
    }

    public function testServerConcurrentServices(): void
    {
        // Register multiple services
        for ($i = 0; $i < 5; $i++) {
            $service = new class($i) extends GreeterService {
                private int $id;
                
                public function __construct(int $id)
                {
                    parent::__construct();
                    $this->id = $id;
                }
                
                public function getServiceName(): string
                {
                    return "test.Service{$this->id}";
                }
            };
            
            $this->server->registerService($service);
        }
        
        $services = $this->server->getServices();
        $this->assertCount(6, $services); // 5 + original greeter service
        
        // All services should be accessible
        for ($i = 0; $i < 5; $i++) {
            $this->assertArrayHasKey("test.Service{$i}", $services);
        }
    }
}