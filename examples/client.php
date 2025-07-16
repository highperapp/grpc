<?php

declare(strict_types=1);

/**
 * Example gRPC Client
 * 
 * Demonstrates how to connect to and use the gRPC server.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\ByteStream\WritableStream;

// Simple gRPC client example
class SimpleGrpcClient
{
    private HttpClient $httpClient;
    private string $baseUrl;
    
    public function __construct(string $host = 'localhost', int $port = 9090)
    {
        $this->httpClient = new HttpClient();
        $this->baseUrl = "http://{$host}:{$port}";
    }
    
    /**
     * Call SayHello method
     */
    public function sayHello(string $name): array
    {
        $request = $this->createRequest('helloworld.Greeter', 'SayHello', [
            'name' => $name
        ]);
        
        return $this->sendRequest($request);
    }
    
    /**
     * Call SayHelloBatch method
     */
    public function sayHelloBatch(array $names): array
    {
        $request = $this->createRequest('helloworld.Greeter', 'SayHelloBatch', [
            'names' => $names
        ]);
        
        return $this->sendRequest($request);
    }
    
    /**
     * Call health check
     */
    public function healthCheck(): array
    {
        $request = $this->createRequest('helloworld.Greeter', 'health', []);
        
        return $this->sendRequest($request);
    }
    
    /**
     * Create gRPC request
     */
    private function createRequest(string $service, string $method, array $data): Request
    {
        $url = $this->baseUrl . "/{$service}/{$method}";
        
        // Simple JSON payload (in real implementation, would use protobuf)
        $body = json_encode($data);
        
        $request = new Request($url, 'POST');
        $request = $request->withHeader('content-type', 'application/grpc+json');
        $request = $request->withHeader('user-agent', 'grpc-php-client/1.0.0');
        $request = $request->withBody($body);
        
        return $request;
    }
    
    /**
     * Send request and parse response
     */
    private function sendRequest(Request $request): array
    {
        try {
            $response = $this->httpClient->request($request);
            
            $statusCode = $response->getStatus();
            $headers = $response->getHeaders();
            $body = $response->getBody()->buffer();
            
            if ($statusCode !== 200) {
                throw new \Exception("Request failed with status {$statusCode}");
            }
            
            // Parse response
            $responseData = json_decode($body, true);
            
            return [
                'success' => true,
                'data' => $responseData,
                'headers' => $headers
            ];
            
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}

// Main client example
function main(): void
{
    $client = new SimpleGrpcClient();
    
    echo "=== gRPC Client Example ===\n\n";
    
    // Test 1: Single greeting
    echo "1. Testing SayHello...\n";
    $result = $client->sayHello('World');
    if ($result['success']) {
        echo "   Response: " . json_encode($result['data']) . "\n";
    } else {
        echo "   Error: " . $result['error'] . "\n";
    }
    
    // Test 2: Batch greetings
    echo "\n2. Testing SayHelloBatch...\n";
    $result = $client->sayHelloBatch(['Alice', 'Bob', 'Charlie']);
    if ($result['success']) {
        echo "   Response: " . json_encode($result['data']) . "\n";
    } else {
        echo "   Error: " . $result['error'] . "\n";
    }
    
    // Test 3: Health check
    echo "\n3. Testing health check...\n";
    $result = $client->healthCheck();
    if ($result['success']) {
        echo "   Response: " . json_encode($result['data']) . "\n";
    } else {
        echo "   Error: " . $result['error'] . "\n";
    }
    
    // Test 4: Load test
    echo "\n4. Running load test (100 requests)...\n";
    $startTime = microtime(true);
    $successCount = 0;
    $errorCount = 0;
    
    for ($i = 0; $i < 100; $i++) {
        $result = $client->sayHello("User{$i}");
        if ($result['success']) {
            $successCount++;
        } else {
            $errorCount++;
        }
        
        if ($i % 20 === 0) {
            echo "   Progress: {$i}/100 requests\n";
        }
    }
    
    $endTime = microtime(true);
    $totalTime = $endTime - $startTime;
    $rps = 100 / $totalTime;
    
    echo "   Results: {$successCount} success, {$errorCount} errors\n";
    echo "   Total time: " . number_format($totalTime, 3) . " seconds\n";
    echo "   Requests per second: " . number_format($rps, 2) . "\n";
    
    echo "\n=== Client Example Complete ===\n";
}

// Run the example
main();