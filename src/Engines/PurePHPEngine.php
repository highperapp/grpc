<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\GRPC\Engines;

use HighPerApp\HighPer\GRPC\Contracts\EngineInterface;
use HighPerApp\HighPer\GRPC\Exceptions\GrpcException;
use Google\Protobuf\Internal\Message;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Pure PHP Engine for gRPC Processing
 * 
 * Fallback engine that provides full gRPC functionality using pure PHP
 * without external dependencies. Optimized for reliability and compatibility.
 */
class PurePHPEngine implements EngineInterface
{
    private LoggerInterface $logger;
    private array $config;
    private array $stats = [
        'operations_total' => 0,
        'operations_success' => 0,
        'operations_error' => 0,
        'avg_processing_time' => 0.0,
        'memory_usage' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0
    ];
    private array $messageCache = [];
    private array $compressionCache = [];

    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->config = array_merge([
            'max_message_size' => 16 * 1024 * 1024, // 16MB
            'compression_level' => 6,
            'cache_enabled' => true,
            'cache_max_size' => 1000,
            'optimization_level' => 'balanced',
            'memory_limit' => '256M',
            'enable_varint_optimization' => true
        ], $config);
        
        $this->logger = $logger ?? new NullLogger();
        
        $this->logger->info('Pure PHP gRPC engine initialized', [
            'config' => $this->config
        ]);
    }

    /**
     * Process gRPC message using pure PHP
     */
    public function processMessage(string $message): string
    {
        $startTime = microtime(true);
        $this->stats['operations_total']++;
        
        try {
            // Validate message
            $this->validateMessage($message);
            
            // Process message (placeholder for actual gRPC processing)
            $result = $this->processMessageInternal($message);
            
            $this->stats['operations_success']++;
            $this->updateProcessingTime($startTime);
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->stats['operations_error']++;
            $this->logger->error('Pure PHP message processing failed', [
                'error' => $e->getMessage(),
                'message_size' => strlen($message)
            ]);
            
            throw new GrpcException("Pure PHP processing failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Serialize protobuf message using pure PHP
     */
    public function serializeMessage(object $message): string
    {
        $startTime = microtime(true);
        $this->stats['operations_total']++;
        
        try {
            if (!($message instanceof Message)) {
                throw new GrpcException('Message must be instance of Google\Protobuf\Internal\Message');
            }
            
            // Check cache first
            $cacheKey = $this->generateCacheKey($message);
            if ($this->config['cache_enabled'] && isset($this->messageCache[$cacheKey])) {
                $this->stats['cache_hits']++;
                return $this->messageCache[$cacheKey];
            }
            
            // Serialize using protobuf
            $serialized = $message->serializeToString();
            
            // Cache result
            if ($this->config['cache_enabled']) {
                $this->cacheResult($cacheKey, $serialized);
                $this->stats['cache_misses']++;
            }
            
            $this->stats['operations_success']++;
            $this->updateProcessingTime($startTime);
            
            return $serialized;
            
        } catch (\Throwable $e) {
            $this->stats['operations_error']++;
            $this->logger->error('Pure PHP serialization failed', [
                'error' => $e->getMessage(),
                'message_class' => get_class($message)
            ]);
            
            throw new GrpcException("Pure PHP serialization failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Deserialize protobuf message using pure PHP
     */
    public function deserializeMessage(string $data, string $messageClass): object
    {
        $startTime = microtime(true);
        $this->stats['operations_total']++;
        
        try {
            if (!class_exists($messageClass)) {
                throw new GrpcException("Message class not found: {$messageClass}");
            }
            
            // Check cache first
            $cacheKey = $this->generateDataCacheKey($data, $messageClass);
            if ($this->config['cache_enabled'] && isset($this->messageCache[$cacheKey])) {
                $this->stats['cache_hits']++;
                return $this->messageCache[$cacheKey];
            }
            
            // Create message instance
            $message = new $messageClass();
            
            if (!($message instanceof Message)) {
                throw new GrpcException("Class {$messageClass} must extend Google\Protobuf\Internal\Message");
            }
            
            // Deserialize from string
            $message->mergeFromString($data);
            
            // Cache result
            if ($this->config['cache_enabled']) {
                $this->cacheResult($cacheKey, $message);
                $this->stats['cache_misses']++;
            }
            
            $this->stats['operations_success']++;
            $this->updateProcessingTime($startTime);
            
            return $message;
            
        } catch (\Throwable $e) {
            $this->stats['operations_error']++;
            $this->logger->error('Pure PHP deserialization failed', [
                'error' => $e->getMessage(),
                'message_class' => $messageClass,
                'data_size' => strlen($data)
            ]);
            
            throw new GrpcException("Pure PHP deserialization failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Compress data using pure PHP
     */
    public function compress(string $data, string $algorithm = 'gzip'): string
    {
        $startTime = microtime(true);
        $this->stats['operations_total']++;
        
        try {
            // Check cache first
            $cacheKey = $this->generateCompressionCacheKey($data, $algorithm);
            if ($this->config['cache_enabled'] && isset($this->compressionCache[$cacheKey])) {
                $this->stats['cache_hits']++;
                return $this->compressionCache[$cacheKey];
            }
            
            $compressed = match ($algorithm) {
                'gzip' => gzencode($data, $this->config['compression_level']),
                'deflate' => deflate($data, $this->config['compression_level']),
                'zstd' => function_exists('zstd_compress') ? 
                    zstd_compress($data, $this->config['compression_level']) : 
                    throw new GrpcException('Zstandard compression not available'),
                default => throw new GrpcException("Unsupported compression algorithm: {$algorithm}")
            };
            
            if ($compressed === false) {
                throw new GrpcException("Compression failed for algorithm: {$algorithm}");
            }
            
            // Cache result
            if ($this->config['cache_enabled']) {
                $this->cacheCompressionResult($cacheKey, $compressed);
                $this->stats['cache_misses']++;
            }
            
            $this->stats['operations_success']++;
            $this->updateProcessingTime($startTime);
            
            return $compressed;
            
        } catch (\Throwable $e) {
            $this->stats['operations_error']++;
            $this->logger->error('Pure PHP compression failed', [
                'error' => $e->getMessage(),
                'algorithm' => $algorithm,
                'data_size' => strlen($data)
            ]);
            
            throw new GrpcException("Pure PHP compression failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Decompress data using pure PHP
     */
    public function decompress(string $data, string $algorithm = 'gzip'): string
    {
        $startTime = microtime(true);
        $this->stats['operations_total']++;
        
        try {
            $decompressed = match ($algorithm) {
                'gzip' => gzdecode($data),
                'deflate' => inflate($data),
                'zstd' => function_exists('zstd_decompress') ? 
                    zstd_decompress($data) : 
                    throw new GrpcException('Zstandard decompression not available'),
                default => throw new GrpcException("Unsupported decompression algorithm: {$algorithm}")
            };
            
            if ($decompressed === false) {
                throw new GrpcException("Decompression failed for algorithm: {$algorithm}");
            }
            
            $this->stats['operations_success']++;
            $this->updateProcessingTime($startTime);
            
            return $decompressed;
            
        } catch (\Throwable $e) {
            $this->stats['operations_error']++;
            $this->logger->error('Pure PHP decompression failed', [
                'error' => $e->getMessage(),
                'algorithm' => $algorithm,
                'data_size' => strlen($data)
            ]);
            
            throw new GrpcException("Pure PHP decompression failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Process varint encoding (optimized for PHP)
     */
    public function encodeVarint(int $value): string
    {
        if (!$this->config['enable_varint_optimization']) {
            return $this->encodeVarintBasic($value);
        }
        
        // Optimized varint encoding
        $result = '';
        while ($value >= 128) {
            $result .= chr(($value & 0x7F) | 0x80);
            $value >>= 7;
        }
        $result .= chr($value);
        
        return $result;
    }

    /**
     * Process varint decoding (optimized for PHP)
     */
    public function decodeVarint(string $data, int &$offset = 0): int
    {
        if (!$this->config['enable_varint_optimization']) {
            return $this->decodeVarintBasic($data, $offset);
        }
        
        // Optimized varint decoding
        $result = 0;
        $shift = 0;
        $pos = $offset;
        
        while ($pos < strlen($data)) {
            $byte = ord($data[$pos]);
            $result |= ($byte & 0x7F) << $shift;
            $pos++;
            
            if (($byte & 0x80) === 0) {
                $offset = $pos;
                return $result;
            }
            
            $shift += 7;
            if ($shift >= 64) {
                throw new GrpcException('Varint too long');
            }
        }
        
        throw new GrpcException('Incomplete varint');
    }

    /**
     * Internal message processing
     */
    private function processMessageInternal(string $message): string
    {
        // This is a placeholder for actual gRPC message processing
        // In a real implementation, this would:
        // 1. Parse HTTP/2 frames
        // 2. Extract gRPC messages
        // 3. Process protobuf data
        // 4. Generate response frames
        
        return $message; // Echo for now
    }

    /**
     * Validate message format and size
     */
    private function validateMessage(string $message): void
    {
        if (strlen($message) > $this->config['max_message_size']) {
            throw new GrpcException('Message exceeds maximum size');
        }
        
        if (empty($message)) {
            throw new GrpcException('Empty message');
        }
    }

    /**
     * Generate cache key for message
     */
    private function generateCacheKey(object $message): string
    {
        return md5(get_class($message) . serialize($message));
    }

    /**
     * Generate cache key for data
     */
    private function generateDataCacheKey(string $data, string $messageClass): string
    {
        return md5($messageClass . $data);
    }

    /**
     * Generate cache key for compression
     */
    private function generateCompressionCacheKey(string $data, string $algorithm): string
    {
        return md5($algorithm . $data);
    }

    /**
     * Cache result with size limit
     */
    private function cacheResult(string $key, mixed $value): void
    {
        if (count($this->messageCache) >= $this->config['cache_max_size']) {
            // Remove oldest entries (simple FIFO)
            $this->messageCache = array_slice($this->messageCache, 100, null, true);
        }
        
        $this->messageCache[$key] = $value;
    }

    /**
     * Cache compression result
     */
    private function cacheCompressionResult(string $key, string $value): void
    {
        if (count($this->compressionCache) >= $this->config['cache_max_size']) {
            $this->compressionCache = array_slice($this->compressionCache, 100, null, true);
        }
        
        $this->compressionCache[$key] = $value;
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
     * Basic varint encoding (fallback)
     */
    private function encodeVarintBasic(int $value): string
    {
        $result = '';
        while ($value > 127) {
            $result .= chr($value & 127 | 128);
            $value >>= 7;
        }
        $result .= chr($value);
        return $result;
    }

    /**
     * Basic varint decoding (fallback)
     */
    private function decodeVarintBasic(string $data, int &$offset = 0): int
    {
        $result = 0;
        $shift = 0;
        
        while ($offset < strlen($data)) {
            $byte = ord($data[$offset]);
            $result |= ($byte & 127) << $shift;
            $offset++;
            
            if (($byte & 128) === 0) {
                return $result;
            }
            
            $shift += 7;
        }
        
        throw new GrpcException('Incomplete varint');
    }

    /**
     * Check if engine is ready
     */
    public function isReady(): bool
    {
        return true; // Pure PHP engine is always ready
    }

    /**
     * Warm up engine
     */
    public function warmUp(): void
    {
        // Pre-allocate cache arrays
        $this->messageCache = [];
        $this->compressionCache = [];
        
        $this->logger->debug('Pure PHP engine warmed up');
    }

    /**
     * Clean up engine resources
     */
    public function cleanup(): void
    {
        $this->messageCache = [];
        $this->compressionCache = [];
        
        $this->logger->debug('Pure PHP engine cleaned up');
    }

    /**
     * Get engine statistics
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'engine_type' => 'pure_php',
            'cache_size' => count($this->messageCache),
            'compression_cache_size' => count($this->compressionCache),
            'success_rate' => $this->stats['operations_total'] > 0 ? 
                ($this->stats['operations_success'] / $this->stats['operations_total']) * 100 : 0,
            'cache_hit_rate' => ($this->stats['cache_hits'] + $this->stats['cache_misses']) > 0 ? 
                ($this->stats['cache_hits'] / ($this->stats['cache_hits'] + $this->stats['cache_misses'])) * 100 : 0,
            'config' => $this->config
        ]);
    }

    /**
     * Get supported compression algorithms
     */
    public function getSupportedCompressions(): array
    {
        return ['gzip', 'deflate'];
    }
}