<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\GRPC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use HighPerApp\HighPer\GRPC\Foundation\GrpcWorkerProcess;
use HighPerApp\HighPer\GRPC\Examples\GreeterService;
use Psr\Log\NullLogger;

/**
 * Unit tests for GrpcWorkerProcess
 */
class GrpcWorkerProcessTest extends TestCase
{
    private GrpcWorkerProcess $worker;
    private GreeterService $service;
    private NullLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new NullLogger();
        $this->service = new GreeterService($this->logger);
        
        $config = [
            'worker_id' => 1,
            'parallel_workers' => 2,
            'circuit_breaker' => [
                'enabled' => true,
                'failure_threshold' => 5
            ],
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3
            ]
        ];
        
        $this->worker = new GrpcWorkerProcess(
            1,
            '127.0.0.1',
            9090,
            $config,
            $this->logger
        );
    }

    public function testWorkerInitialization(): void
    {
        $this->assertEquals(1, $this->worker->getWorkerId());
        $this->assertTrue($this->worker->isHealthy());
        $this->assertIsArray($this->worker->getStats());
    }

    public function testServiceRegistration(): void
    {
        $this->worker->registerService($this->service);
        
        $services = $this->worker->getRegisteredServices();
        $this->assertArrayHasKey('helloworld.Greeter', $services);
        $this->assertInstanceOf(GreeterService::class, $services['helloworld.Greeter']);
    }

    public function testServiceUnregistration(): void
    {
        $this->worker->registerService($this->service);
        $this->worker->unregisterService('helloworld.Greeter');
        
        $services = $this->worker->getRegisteredServices();
        $this->assertArrayNotHasKey('helloworld.Greeter', $services);
    }

    public function testWorkerStats(): void
    {
        $stats = $this->worker->getStats();
        
        $this->assertArrayHasKey('worker_id', $stats);
        $this->assertArrayHasKey('requests_processed', $stats);
        $this->assertArrayHasKey('avg_response_time', $stats);
        $this->assertArrayHasKey('health_status', $stats);
        
        $this->assertEquals(1, $stats['worker_id']);
        $this->assertIsNumeric($stats['requests_processed']);
        $this->assertIsNumeric($stats['avg_response_time']);
    }

    public function testWorkerConfiguration(): void
    {
        $config = $this->worker->getConfiguration();
        
        $this->assertArrayHasKey('worker_id', $config);
        $this->assertArrayHasKey('parallel_workers', $config);
        $this->assertArrayHasKey('circuit_breaker', $config);
        $this->assertArrayHasKey('retry', $config);
        
        $this->assertEquals(1, $config['worker_id']);
        $this->assertEquals(2, $config['parallel_workers']);
        $this->assertTrue($config['circuit_breaker']['enabled']);
        $this->assertTrue($config['retry']['enabled']);
    }

    public function testHealthCheck(): void
    {
        $this->assertTrue($this->worker->isHealthy());
        
        $healthStatus = $this->worker->getHealthStatus();
        $this->assertIsArray($healthStatus);
        $this->assertArrayHasKey('healthy', $healthStatus);
        $this->assertArrayHasKey('components', $healthStatus);
    }

    public function testWorkerLifecycle(): void
    {
        // Worker should start successfully
        $this->worker->start();
        $this->assertTrue($this->worker->isRunning());
        
        // Worker should stop gracefully
        $this->worker->stop();
        $this->assertFalse($this->worker->isRunning());
    }

    protected function tearDown(): void
    {
        if ($this->worker->isRunning()) {
            $this->worker->stop();
        }
    }
}