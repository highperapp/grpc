<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\GRPC\Engines;

use HighPerApp\HighPer\GRPC\Engines\RustFFIEngine;
use HighPerApp\HighPer\GRPC\Engines\PurePHPEngine;
use HighPerApp\HighPer\GRPC\Exceptions\GrpcException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Hybrid Engine for gRPC Processing
 * 
 * Automatically selects between Rust FFI acceleration and pure PHP fallback
 * based on availability and performance characteristics.
 * 
 * Integrates with HighPer framework's performance optimization patterns.
 */
class HybridEngine
{
    private RustFFIEngine|PurePHPEngine $activeEngine;
    private LoggerInterface $logger;
    private array $config;
    private array $stats = [
        'engine_type' => null,
        'rust_ffi_available' => false,
        'operations_total' => 0,
        'operations_rust' => 0,
        'operations_php' => 0,
        'avg_processing_time' => 0.0,
        'fallback_count' => 0,
        'initialization_time' => 0.0
    ];

    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->config = array_merge([
            'rust_acceleration' => true,
            'fallback_to_php' => true,
            'performance_threshold' => 0.001, // 1ms threshold for engine switching
            'auto_fallback' => true,
            'rust_ffi_timeout' => 5, // seconds
            'optimization_level' => 'balanced', // 'speed', 'balanced', 'memory'
        ], $config);
        
        $this->logger = $logger ?? new NullLogger();
        
        $this->initializeEngine();
    }

    private function initializeEngine(): void
    {
        $initStart = microtime(true);
        
        // Check if Rust FFI is available and enabled
        if ($this->config['rust_acceleration'] && $this->isRustFFIAvailable()) {
            try {
                $this->activeEngine = new RustFFIEngine($this->config, $this->logger);
                $this->stats['engine_type'] = 'rust_ffi';
                $this->stats['rust_ffi_available'] = true;
                
                $this->logger->info('Hybrid engine initialized with Rust FFI acceleration');
                
            } catch (\Throwable $e) {
                $this->handleRustFFIFailure($e);
            }
        } else {
            $this->initializePurePHPEngine();
        }
        
        $this->stats['initialization_time'] = microtime(true) - $initStart;
    }

    private function handleRustFFIFailure(\Throwable $e): void
    {
        $this->stats['fallback_count']++;
        
        if ($this->config['fallback_to_php']) {
            $this->initializePurePHPEngine();
            $this->logger->warning('Rust FFI failed, falling back to pure PHP', [
                'error' => $e->getMessage(),
                'fallback_count' => $this->stats['fallback_count']
            ]);
        } else {
            throw new GrpcException('Rust FFI initialization failed and fallback disabled', 0, $e);
        }
    }

    private function initializePurePHPEngine(): void
    {
        $this->activeEngine = new PurePHPEngine($this->config, $this->logger);
        $this->stats['engine_type'] = 'pure_php';
        $this->stats['rust_ffi_available'] = false;
        
        $this->logger->info('Hybrid engine initialized with pure PHP engine');
    }

    private function isRustFFIAvailable(): bool
    {
        // Check if Rust FFI extension is loaded
        if (!extension_loaded('ffi')) {
            $this->logger->debug('FFI extension not available');
            return false;
        }
        
        // Check if Rust library is available
        $rustLibPath = $this->getRustLibraryPath();
        if (!file_exists($rustLibPath)) {
            $this->logger->debug('Rust library not found', ['path' => $rustLibPath]);
            return false;
        }
        
        return true;
    }

    private function getRustLibraryPath(): string
    {
        $baseDir = dirname(__DIR__, 2);
        $libName = PHP_OS_FAMILY === 'Windows' ? 'grpc_rust.dll' : 'libgrpc_rust.so';
        return $baseDir . '/rust/target/release/' . $libName;
    }

    /**
     * Process gRPC message with hybrid engine
     */
    public function processMessage(string $message): string
    {
        $startTime = microtime(true);
        $this->stats['operations_total']++;
        
        try {
            $result = $this->activeEngine->processMessage($message);
            
            $processingTime = microtime(true) - $startTime;
            $this->updateStats($processingTime);
            
            return $result;
            
        } catch (\Throwable $e) {
            // Auto-fallback on error if enabled
            if ($this->config['auto_fallback'] && $this->canFallback()) {
                return $this->fallbackProcessMessage($message, $e);
            }
            
            throw $e;
        }
    }

    /**
     * Serialize protobuf message
     */
    public function serializeMessage(object $message): string
    {
        $startTime = microtime(true);
        $this->stats['operations_total']++;
        
        try {
            $result = $this->activeEngine->serializeMessage($message);
            
            $processingTime = microtime(true) - $startTime;
            $this->updateStats($processingTime);
            
            return $result;
            
        } catch (\Throwable $e) {
            if ($this->config['auto_fallback'] && $this->canFallback()) {
                return $this->fallbackSerializeMessage($message, $e);
            }
            
            throw $e;
        }
    }

    /**
     * Deserialize protobuf message
     */
    public function deserializeMessage(string $data, string $messageClass): object
    {
        $startTime = microtime(true);
        $this->stats['operations_total']++;
        
        try {
            $result = $this->activeEngine->deserializeMessage($data, $messageClass);
            
            $processingTime = microtime(true) - $startTime;
            $this->updateStats($processingTime);
            
            return $result;
            
        } catch (\Throwable $e) {
            if ($this->config['auto_fallback'] && $this->canFallback()) {
                return $this->fallbackDeserializeMessage($data, $messageClass, $e);
            }
            
            throw $e;
        }
    }

    /**
     * Compress data using hybrid engine
     */
    public function compress(string $data, string $algorithm = 'gzip'): string
    {
        $startTime = microtime(true);
        $this->stats['operations_total']++;
        
        try {
            $result = $this->activeEngine->compress($data, $algorithm);
            
            $processingTime = microtime(true) - $startTime;
            $this->updateStats($processingTime);
            
            return $result;
            
        } catch (\Throwable $e) {
            if ($this->config['auto_fallback'] && $this->canFallback()) {
                return $this->fallbackCompress($data, $algorithm, $e);
            }
            
            throw $e;
        }
    }

    /**
     * Decompress data using hybrid engine
     */
    public function decompress(string $data, string $algorithm = 'gzip'): string
    {
        $startTime = microtime(true);
        $this->stats['operations_total']++;
        
        try {
            $result = $this->activeEngine->decompress($data, $algorithm);
            
            $processingTime = microtime(true) - $startTime;
            $this->updateStats($processingTime);
            
            return $result;
            
        } catch (\Throwable $e) {
            if ($this->config['auto_fallback'] && $this->canFallback()) {
                return $this->fallbackDecompress($data, $algorithm, $e);
            }
            
            throw $e;
        }
    }

    /**
     * Fallback processing methods
     */
    private function fallbackProcessMessage(string $message, \Throwable $originalError): string
    {
        $this->stats['fallback_count']++;
        
        $this->logger->warning('Falling back to PHP engine for message processing', [
            'original_error' => $originalError->getMessage(),
            'fallback_count' => $this->stats['fallback_count']
        ]);
        
        $fallbackEngine = new PurePHPEngine($this->config, $this->logger);
        return $fallbackEngine->processMessage($message);
    }

    private function fallbackSerializeMessage(object $message, \Throwable $originalError): string
    {
        $this->stats['fallback_count']++;
        
        $this->logger->warning('Falling back to PHP engine for serialization', [
            'original_error' => $originalError->getMessage(),
            'fallback_count' => $this->stats['fallback_count']
        ]);
        
        $fallbackEngine = new PurePHPEngine($this->config, $this->logger);
        return $fallbackEngine->serializeMessage($message);
    }

    private function fallbackDeserializeMessage(string $data, string $messageClass, \Throwable $originalError): object
    {
        $this->stats['fallback_count']++;
        
        $this->logger->warning('Falling back to PHP engine for deserialization', [
            'original_error' => $originalError->getMessage(),
            'fallback_count' => $this->stats['fallback_count']
        ]);
        
        $fallbackEngine = new PurePHPEngine($this->config, $this->logger);
        return $fallbackEngine->deserializeMessage($data, $messageClass);
    }

    private function fallbackCompress(string $data, string $algorithm, \Throwable $originalError): string
    {
        $this->stats['fallback_count']++;
        
        $this->logger->warning('Falling back to PHP engine for compression', [
            'original_error' => $originalError->getMessage(),
            'fallback_count' => $this->stats['fallback_count']
        ]);
        
        $fallbackEngine = new PurePHPEngine($this->config, $this->logger);
        return $fallbackEngine->compress($data, $algorithm);
    }

    private function fallbackDecompress(string $data, string $algorithm, \Throwable $originalError): string
    {
        $this->stats['fallback_count']++;
        
        $this->logger->warning('Falling back to PHP engine for decompression', [
            'original_error' => $originalError->getMessage(),
            'fallback_count' => $this->stats['fallback_count']
        ]);
        
        $fallbackEngine = new PurePHPEngine($this->config, $this->logger);
        return $fallbackEngine->decompress($data, $algorithm);
    }

    private function canFallback(): bool
    {
        return $this->config['fallback_to_php'] && 
               $this->stats['engine_type'] === 'rust_ffi' &&
               $this->stats['fallback_count'] < 10; // Prevent infinite fallback loops
    }

    private function updateStats(float $processingTime): void
    {
        // Update engine-specific counters
        if ($this->stats['engine_type'] === 'rust_ffi') {
            $this->stats['operations_rust']++;
        } else {
            $this->stats['operations_php']++;
        }
        
        // Update average processing time (exponential moving average)
        $alpha = 0.1;
        $this->stats['avg_processing_time'] = ($alpha * $processingTime) + 
            ((1 - $alpha) * $this->stats['avg_processing_time']);
    }

    /**
     * Get engine statistics
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'active_engine' => get_class($this->activeEngine),
            'rust_ffi_percentage' => $this->stats['operations_total'] > 0 ? 
                ($this->stats['operations_rust'] / $this->stats['operations_total']) * 100 : 0,
            'fallback_percentage' => $this->stats['operations_total'] > 0 ? 
                ($this->stats['fallback_count'] / $this->stats['operations_total']) * 100 : 0,
            'config' => $this->config
        ]);
    }

    /**
     * Get active engine instance
     */
    public function getActiveEngine(): RustFFIEngine|PurePHPEngine
    {
        return $this->activeEngine;
    }

    /**
     * Check if engine is ready for processing
     */
    public function isReady(): bool
    {
        return method_exists($this->activeEngine, 'isReady') ? 
            $this->activeEngine->isReady() : true;
    }

    /**
     * Warm up engine (pre-load resources)
     */
    public function warmUp(): void
    {
        if (method_exists($this->activeEngine, 'warmUp')) {
            $this->activeEngine->warmUp();
        }
    }

    /**
     * Clean up engine resources
     */
    public function cleanup(): void
    {
        if (method_exists($this->activeEngine, 'cleanup')) {
            $this->activeEngine->cleanup();
        }
    }
}