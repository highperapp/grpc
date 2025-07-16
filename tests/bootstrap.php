<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Mock service for testing
class MockGrpcService
{
    private $logger;
    
    public function __construct($logger = null)
    {
        $this->logger = $logger;
    }
    
    public function getServiceName(): string
    {
        return 'MockService';
    }
    
    public function getServiceVersion(): string
    {
        return '1.0.0';
    }
    
    public function getMethods(): array
    {
        return [
            'TestMethod' => [
                'input_type' => 'string',
                'output_type' => 'string'
            ]
        ];
    }
    
    public function TestMethod(string $input): string
    {
        return "Echo: " . $input;
    }
}