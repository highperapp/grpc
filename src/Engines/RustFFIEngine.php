<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\GRPC\Engines;

use HighPerApp\HighPer\GRPC\Exceptions\GrpcException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use FFI;

/**
 * Rust FFI Engine for High-Performance gRPC Processing
 * 
 * Provides native Rust acceleration for gRPC operations using FFI.
 * Falls back to pure PHP implementation if Rust library is unavailable.
 * 
 * Integrates with HighPer framework's performance optimization patterns.
 */
class RustFFIEngine
{
    private FFI $ffi;
    private LoggerInterface $logger;
    private array $config;
    private bool $initialized = false;
    private array $stats = [
        'operations_total' => 0,
        'operations_success' => 0,
        'operations_error' => 0,
        'ffi_calls' => 0,
        'avg_processing_time' => 0.0,
        'memory_usage' => 0,
        'rust_panics' => 0,
        'initialization_time' => 0.0
    ];

    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->config = array_merge([
            'rust_library_path' => $this->getDefaultLibraryPath(),
            'enable_logging' => true,
            'panic_handling' => true,
            'memory_pool_size' => 64 * 1024 * 1024, // 64MB
            'max_concurrent_operations' => 1000,
            'operation_timeout' => 30, // seconds
            'enable_metrics' => true,
            'debug_mode' => false
        ], $config);
        
        $this->logger = $logger ?? new NullLogger();
        
        $this->initialize();
    }

    private function initialize(): void
    {
        $startTime = microtime(true);
        
        try {
            // Check if FFI extension is loaded
            if (!extension_loaded('ffi')) {
                throw new GrpcException('FFI extension is not loaded');
            }
            
            // Check if Rust library exists
            if (!file_exists($this->config['rust_library_path'])) {
                throw new GrpcException("Rust library not found: {$this->config['rust_library_path']}");
            }
            
            // Initialize FFI with Rust library
            $this->ffi = FFI::cdef($this->getCDefinitions(), $this->config['rust_library_path']);
            
            // Initialize Rust engine
            $initResult = $this->ffi->grpc_engine_init(
                $this->config['memory_pool_size'],
                $this->config['max_concurrent_operations'],
                $this->config['enable_logging'] ? 1 : 0,
                $this->config['debug_mode'] ? 1 : 0
            );
            
            if ($initResult !== 0) {
                throw new GrpcException("Rust engine initialization failed with code: {$initResult}");
            }
            
            $this->initialized = true;
            $this->stats['initialization_time'] = microtime(true) - $startTime;
            
            $this->logger->info('Rust FFI gRPC engine initialized successfully', [
                'library_path' => $this->config['rust_library_path'],
                'initialization_time' => $this->stats['initialization_time']
            ]);
            
        } catch (\Throwable $e) {
            $this->logger->error('Rust FFI engine initialization failed', [
                'error' => $e->getMessage(),
                'library_path' => $this->config['rust_library_path']
            ]);
            
            throw new GrpcException("Rust FFI initialization failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Process gRPC message using Rust FFI
     */
    public function processMessage(string $message): string
    {
        if (!$this->initialized) {
            throw new GrpcException('Rust FFI engine not initialized');
        }
        
        $startTime = microtime(true);
        $this->stats['operations_total']++;
        $this->stats['ffi_calls']++;
        
        try {
            // Create FFI string from PHP string
            $inputBuffer = $this->createFFIString($message);
            $inputLength = strlen($message);
            
            // Allocate output buffer
            $outputBuffer = $this->ffi->new('char[' . (strlen($message) * 2) . ']');
            $outputLength = $this->ffi->new('size_t');
            
            // Call Rust function
            $result = $this->ffi->grpc_process_message(
                $inputBuffer,
                $inputLength,
                $outputBuffer,
                FFI::addr($outputLength)
            );
            
            if ($result !== 0) {
                throw new GrpcException("Rust processing failed with code: {$result}");
            }
            
            // Convert FFI string back to PHP string
            $output = $this->ffiStringToPhp($outputBuffer, $outputLength->cdata);
            
            $this->stats['operations_success']++;
            $this->updateProcessingTime($startTime);
            
            return $output;
            
        } catch (\Throwable $e) {
            $this->stats['operations_error']++;
            
            // Check if this was a Rust panic
            if (str_contains($e->getMessage(), 'panic')) {
                $this->stats['rust_panics']++;
                $this->handleRustPanic($e);
            }
            
            $this->logger->error('Rust FFI message processing failed', [
                'error' => $e->getMessage(),
                'message_size' => strlen($message)
            ]);
            
            throw new GrpcException("Rust FFI processing failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Serialize protobuf message using Rust FFI
     */
    public function serializeMessage(object $message): string
    {
        if (!$this->initialized) {
            throw new GrpcException('Rust FFI engine not initialized');
        }
        
        $startTime = microtime(true);
        $this->stats['operations_total']++;
        $this->stats['ffi_calls']++;
        
        try {
            // First serialize using PHP to get binary data
            if (!method_exists($message, 'serializeToString')) {
                throw new GrpcException('Message must have serializeToString method');
            }
            
            $phpSerialized = $message->serializeToString();
            
            // Create FFI string
            $inputBuffer = $this->createFFIString($phpSerialized);
            $inputLength = strlen($phpSerialized);
            
            // Allocate output buffer
            $outputBuffer = $this->ffi->new('char[' . (strlen($phpSerialized) * 2) . ']');
            $outputLength = $this->ffi->new('size_t');
            
            // Call Rust serialization optimization
            $result = $this->ffi->grpc_serialize_message(
                $inputBuffer,
                $inputLength,
                $outputBuffer,
                FFI::addr($outputLength)
            );
            
            if ($result !== 0) {
                // Fall back to PHP serialization if Rust fails
                $this->logger->debug('Rust serialization failed, using PHP fallback', [
                    'result_code' => $result
                ]);
                return $phpSerialized;
            }
            
            $output = $this->ffiStringToPhp($outputBuffer, $outputLength->cdata);
            
            $this->stats['operations_success']++;
            $this->updateProcessingTime($startTime);
            
            return $output;
            
        } catch (\Throwable $e) {
            $this->stats['operations_error']++;
            
            $this->logger->error('Rust FFI serialization failed', [
                'error' => $e->getMessage(),
                'message_class' => get_class($message)
            ]);
            
            throw new GrpcException("Rust FFI serialization failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Deserialize protobuf message using Rust FFI
     */
    public function deserializeMessage(string $data, string $messageClass): object
    {
        if (!$this->initialized) {
            throw new GrpcException('Rust FFI engine not initialized');
        }
        
        $startTime = microtime(true);
        $this->stats['operations_total']++;
        $this->stats['ffi_calls']++;
        
        try {
            // Create FFI string
            $inputBuffer = $this->createFFIString($data);
            $inputLength = strlen($data);
            
            // Allocate output buffer
            $outputBuffer = $this->ffi->new('char[' . (strlen($data) * 2) . ']');
            $outputLength = $this->ffi->new('size_t');
            
            // Call Rust deserialization optimization
            $result = $this->ffi->grpc_deserialize_message(
                $inputBuffer,
                $inputLength,
                $outputBuffer,
                FFI::addr($outputLength)
            );
            
            if ($result !== 0) {
                // Fall back to PHP deserialization
                $this->logger->debug('Rust deserialization failed, using PHP fallback', [
                    'result_code' => $result
                ]);
                
                if (!class_exists($messageClass)) {
                    throw new GrpcException("Message class not found: {$messageClass}");
                }
                
                $message = new $messageClass();
                $message->mergeFromString($data);
                return $message;
            }
            
            $optimizedData = $this->ffiStringToPhp($outputBuffer, $outputLength->cdata);
            
            // Create PHP message object
            if (!class_exists($messageClass)) {
                throw new GrpcException("Message class not found: {$messageClass}");
            }
            
            $message = new $messageClass();
            $message->mergeFromString($optimizedData);
            
            $this->stats['operations_success']++;
            $this->updateProcessingTime($startTime);
            
            return $message;
            
        } catch (\Throwable $e) {
            $this->stats['operations_error']++;
            
            $this->logger->error('Rust FFI deserialization failed', [
                'error' => $e->getMessage(),
                'message_class' => $messageClass,
                'data_size' => strlen($data)
            ]);
            
            throw new GrpcException("Rust FFI deserialization failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Compress data using Rust FFI
     */
    public function compress(string $data, string $algorithm = 'gzip'): string
    {
        if (!$this->initialized) {
            throw new GrpcException('Rust FFI engine not initialized');
        }
        
        $startTime = microtime(true);
        $this->stats['operations_total']++;
        $this->stats['ffi_calls']++;
        
        try {
            // Map algorithm to Rust enum
            $algorithmCode = match ($algorithm) {
                'gzip' => 0,
                'deflate' => 1,
                'zstd' => 2,
                default => throw new GrpcException("Unsupported compression algorithm: {$algorithm}")
            };
            
            // Create FFI string
            $inputBuffer = $this->createFFIString($data);
            $inputLength = strlen($data);
            
            // Allocate output buffer (assume 90% compression ratio)
            $maxOutputSize = max(1024, intval(strlen($data) * 1.1));
            $outputBuffer = $this->ffi->new("char[{$maxOutputSize}]");
            $outputLength = $this->ffi->new('size_t');
            
            // Call Rust compression
            $result = $this->ffi->grpc_compress_data(
                $inputBuffer,
                $inputLength,
                $outputBuffer,
                FFI::addr($outputLength),
                $algorithmCode
            );
            
            if ($result !== 0) {
                throw new GrpcException("Rust compression failed with code: {$result}");
            }
            
            $output = $this->ffiStringToPhp($outputBuffer, $outputLength->cdata);
            
            $this->stats['operations_success']++;
            $this->updateProcessingTime($startTime);
            
            return $output;
            
        } catch (\Throwable $e) {
            $this->stats['operations_error']++;
            
            $this->logger->error('Rust FFI compression failed', [
                'error' => $e->getMessage(),
                'algorithm' => $algorithm,
                'data_size' => strlen($data)
            ]);
            
            throw new GrpcException("Rust FFI compression failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Decompress data using Rust FFI
     */
    public function decompress(string $data, string $algorithm = 'gzip'): string
    {
        if (!$this->initialized) {
            throw new GrpcException('Rust FFI engine not initialized');
        }
        
        $startTime = microtime(true);
        $this->stats['operations_total']++;
        $this->stats['ffi_calls']++;
        
        try {
            // Map algorithm to Rust enum
            $algorithmCode = match ($algorithm) {
                'gzip' => 0,
                'deflate' => 1,
                'zstd' => 2,
                default => throw new GrpcException("Unsupported decompression algorithm: {$algorithm}")
            };
            
            // Create FFI string
            $inputBuffer = $this->createFFIString($data);
            $inputLength = strlen($data);
            
            // Allocate output buffer (assume 10x expansion)
            $maxOutputSize = max(1024, strlen($data) * 10);
            $outputBuffer = $this->ffi->new("char[{$maxOutputSize}]");
            $outputLength = $this->ffi->new('size_t');
            
            // Call Rust decompression
            $result = $this->ffi->grpc_decompress_data(
                $inputBuffer,
                $inputLength,
                $outputBuffer,
                FFI::addr($outputLength),
                $algorithmCode
            );
            
            if ($result !== 0) {
                throw new GrpcException("Rust decompression failed with code: {$result}");
            }
            
            $output = $this->ffiStringToPhp($outputBuffer, $outputLength->cdata);
            
            $this->stats['operations_success']++;
            $this->updateProcessingTime($startTime);
            
            return $output;
            
        } catch (\Throwable $e) {
            $this->stats['operations_error']++;
            
            $this->logger->error('Rust FFI decompression failed', [
                'error' => $e->getMessage(),
                'algorithm' => $algorithm,
                'data_size' => strlen($data)
            ]);
            
            throw new GrpcException("Rust FFI decompression failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Get C function definitions for FFI
     */
    private function getCDefinitions(): string
    {
        return '
            // Engine management
            int grpc_engine_init(size_t memory_pool_size, size_t max_concurrent_ops, int enable_logging, int debug_mode);
            void grpc_engine_cleanup(void);
            int grpc_engine_health_check(void);
            
            // Message processing
            int grpc_process_message(const char* input, size_t input_len, char* output, size_t* output_len);
            int grpc_serialize_message(const char* input, size_t input_len, char* output, size_t* output_len);
            int grpc_deserialize_message(const char* input, size_t input_len, char* output, size_t* output_len);
            
            // Compression
            int grpc_compress_data(const char* input, size_t input_len, char* output, size_t* output_len, int algorithm);
            int grpc_decompress_data(const char* input, size_t input_len, char* output, size_t* output_len, int algorithm);
            
            // Metrics
            int grpc_get_metrics(char* output, size_t* output_len);
            void grpc_reset_metrics(void);
            
            // Error handling
            int grpc_get_last_error(char* output, size_t* output_len);
            void grpc_clear_last_error(void);
        ';
    }

    /**
     * Get default library path
     */
    private function getDefaultLibraryPath(): string
    {
        $baseDir = dirname(__DIR__, 2);
        $libName = PHP_OS_FAMILY === 'Windows' ? 'grpc_rust.dll' : 'libgrpc_rust.so';
        return $baseDir . '/rust/target/release/' . $libName;
    }

    /**
     * Create FFI string from PHP string
     */
    private function createFFIString(string $phpString): object
    {
        $length = strlen($phpString);
        $ffiString = $this->ffi->new("char[{$length}]");
        
        for ($i = 0; $i < $length; $i++) {
            $ffiString[$i] = $phpString[$i];
        }
        
        return $ffiString;
    }

    /**
     * Convert FFI string to PHP string
     */
    private function ffiStringToPhp(object $ffiString, int $length): string
    {
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= $ffiString[$i];
        }
        return $result;
    }

    /**
     * Handle Rust panic
     */
    private function handleRustPanic(\Throwable $e): void
    {
        $this->logger->critical('Rust FFI panic detected', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        // Attempt to reinitialize engine
        if ($this->config['panic_handling']) {
            try {
                $this->initialize();
            } catch (\Throwable $reinitError) {
                $this->logger->error('Failed to reinitialize after panic', [
                    'error' => $reinitError->getMessage()
                ]);
            }
        }
    }

    /**
     * Update processing time statistics
     */
    private function updateProcessingTime(float $startTime): void
    {
        $processingTime = microtime(true) - $startTime;
        $alpha = 0.1; // Exponential moving average factor
        $this->stats['avg_processing_time'] = ($alpha * $processingTime) + 
            ((1 - $alpha) * $this->stats['avg_processing_time']);
        
        $this->stats['memory_usage'] = memory_get_usage(true);
    }

    /**
     * Check if engine is ready
     */
    public function isReady(): bool
    {
        if (!$this->initialized) {
            return false;
        }
        
        try {
            $healthCheck = $this->ffi->grpc_engine_health_check();
            return $healthCheck === 0;
        } catch (\Throwable $e) {
            $this->logger->warning('Health check failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Warm up engine
     */
    public function warmUp(): void
    {
        if (!$this->initialized) {
            return;
        }
        
        try {
            // Warm up with small test operations
            $testData = "test_warmup_data";
            $this->processMessage($testData);
            
            $this->logger->debug('Rust FFI engine warmed up successfully');
        } catch (\Throwable $e) {
            $this->logger->warning('Engine warmup failed', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Clean up engine resources
     */
    public function cleanup(): void
    {
        if ($this->initialized) {
            try {
                $this->ffi->grpc_engine_cleanup();
                $this->logger->info('Rust FFI engine cleaned up');
            } catch (\Throwable $e) {
                $this->logger->warning('Engine cleanup failed', [
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        $this->initialized = false;
    }

    /**
     * Get engine statistics
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'engine_type' => 'rust_ffi',
            'initialized' => $this->initialized,
            'success_rate' => $this->stats['operations_total'] > 0 ? 
                ($this->stats['operations_success'] / $this->stats['operations_total']) * 100 : 0,
            'panic_rate' => $this->stats['ffi_calls'] > 0 ? 
                ($this->stats['rust_panics'] / $this->stats['ffi_calls']) * 100 : 0,
            'config' => $this->config
        ]);
    }

    /**
     * Get last Rust error
     */
    public function getLastError(): ?string
    {
        if (!$this->initialized) {
            return null;
        }
        
        try {
            $errorBuffer = $this->ffi->new('char[1024]');
            $errorLength = $this->ffi->new('size_t');
            
            $result = $this->ffi->grpc_get_last_error($errorBuffer, FFI::addr($errorLength));
            
            if ($result === 0 && $errorLength->cdata > 0) {
                return $this->ffiStringToPhp($errorBuffer, $errorLength->cdata);
            }
            
            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Destructor - cleanup resources
     */
    public function __destruct()
    {
        $this->cleanup();
    }
}