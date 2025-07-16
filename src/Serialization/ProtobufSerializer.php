<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\GRPC\Serialization;

use HighPerApp\HighPer\GRPC\Exceptions\GrpcException;
use Google\Protobuf\Internal\Message;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Protobuf Serializer for gRPC Messages
 * 
 * Handles serialization and deserialization of protobuf messages
 * with optimizations for HighPer framework's performance requirements.
 */
class ProtobufSerializer
{
    private LoggerInterface $logger;
    private array $config;
    private array $messageClassCache = [];
    private array $serializationCache = [];
    private array $stats = [
        'serializations' => 0,
        'deserializations' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'avg_serialization_time' => 0.0,
        'avg_deserialization_time' => 0.0,
        'total_bytes_processed' => 0
    ];

    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->config = array_merge([
            'cache_enabled' => true,
            'cache_max_size' => 1000,
            'cache_ttl' => 3600, // 1 hour
            'validate_messages' => true,
            'compress_cache' => false,
            'enable_reflection' => true,
            'strict_mode' => false
        ], $config);
        
        $this->logger = $logger ?? new NullLogger();
        
        $this->logger->info('Protobuf serializer initialized', [
            'config' => $this->config
        ]);
    }

    /**
     * Serialize protobuf message to string
     */
    public function serialize(object $message): string
    {
        $startTime = microtime(true);
        $this->stats['serializations']++;
        
        try {
            // Validate message type
            if (!($message instanceof Message)) {
                throw new GrpcException('Object must be instance of Google\Protobuf\Internal\Message');
            }
            
            // Check cache first
            if ($this->config['cache_enabled']) {
                $cacheKey = $this->generateSerializationCacheKey($message);
                if (isset($this->serializationCache[$cacheKey])) {
                    $this->stats['cache_hits']++;
                    return $this->serializationCache[$cacheKey]['data'];
                }
            }
            
            // Validate message if enabled
            if ($this->config['validate_messages']) {
                $this->validateMessage($message);
            }
            
            // Serialize message
            $serialized = $message->serializeToString();
            
            if ($serialized === false || $serialized === null) {
                throw new GrpcException('Failed to serialize protobuf message');
            }
            
            // Cache result
            if ($this->config['cache_enabled']) {
                $this->cacheSerializationResult($cacheKey, $serialized);
                $this->stats['cache_misses']++;
            }
            
            $this->stats['total_bytes_processed'] += strlen($serialized);
            $this->updateSerializationTime($startTime);
            
            return $serialized;
            
        } catch (\Throwable $e) {
            $this->logger->error('Protobuf serialization failed', [
                'message_class' => get_class($message),
                'error' => $e->getMessage()
            ]);
            
            throw new GrpcException("Protobuf serialization failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Deserialize string to protobuf message
     */
    public function deserialize(string $data, string $messageClass): object
    {
        $startTime = microtime(true);
        $this->stats['deserializations']++;
        
        try {
            // Validate message class
            if (!$this->isValidMessageClass($messageClass)) {
                throw new GrpcException("Invalid message class: {$messageClass}");
            }
            
            // Check cache first
            if ($this->config['cache_enabled']) {
                $cacheKey = $this->generateDeserializationCacheKey($data, $messageClass);
                if (isset($this->serializationCache[$cacheKey])) {
                    $this->stats['cache_hits']++;
                    return clone $this->serializationCache[$cacheKey]['data'];
                }
            }
            
            // Create message instance
            $message = $this->createMessageInstance($messageClass);
            
            // Deserialize from string
            $message->mergeFromString($data);
            
            // Validate deserialized message if enabled
            if ($this->config['validate_messages']) {
                $this->validateMessage($message);
            }
            
            // Cache result
            if ($this->config['cache_enabled']) {
                $this->cacheDeserializationResult($cacheKey, $message);
                $this->stats['cache_misses']++;
            }
            
            $this->stats['total_bytes_processed'] += strlen($data);
            $this->updateDeserializationTime($startTime);
            
            return $message;
            
        } catch (\Throwable $e) {
            $this->logger->error('Protobuf deserialization failed', [
                'message_class' => $messageClass,
                'data_size' => strlen($data),
                'error' => $e->getMessage()
            ]);
            
            throw new GrpcException("Protobuf deserialization failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Serialize message to JSON (for debugging/logging)
     */
    public function serializeToJson(object $message): string
    {
        try {
            if (!($message instanceof Message)) {
                throw new GrpcException('Object must be instance of Google\Protobuf\Internal\Message');
            }
            
            return $message->serializeToJsonString();
            
        } catch (\Throwable $e) {
            throw new GrpcException("JSON serialization failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Deserialize message from JSON
     */
    public function deserializeFromJson(string $json, string $messageClass): object
    {
        try {
            if (!$this->isValidMessageClass($messageClass)) {
                throw new GrpcException("Invalid message class: {$messageClass}");
            }
            
            $message = $this->createMessageInstance($messageClass);
            $message->mergeFromJsonString($json);
            
            return $message;
            
        } catch (\Throwable $e) {
            throw new GrpcException("JSON deserialization failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Validate message class exists and is valid
     */
    private function isValidMessageClass(string $messageClass): bool
    {
        // Check cache first
        if (isset($this->messageClassCache[$messageClass])) {
            return $this->messageClassCache[$messageClass];
        }
        
        $valid = false;
        
        try {
            if (class_exists($messageClass)) {
                $reflection = new \ReflectionClass($messageClass);
                $valid = $reflection->isSubclassOf(Message::class);
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Message class validation failed', [
                'class' => $messageClass,
                'error' => $e->getMessage()
            ]);
        }
        
        // Cache result
        $this->messageClassCache[$messageClass] = $valid;
        
        return $valid;
    }

    /**
     * Create message instance safely
     */
    private function createMessageInstance(string $messageClass): Message
    {
        try {
            $instance = new $messageClass();
            
            if (!($instance instanceof Message)) {
                throw new GrpcException("Class {$messageClass} must extend Google\\Protobuf\\Internal\\Message");
            }
            
            return $instance;
            
        } catch (\Throwable $e) {
            throw new GrpcException("Failed to create message instance: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Validate message structure and content
     */
    private function validateMessage(Message $message): void
    {
        if (!$this->config['validate_messages']) {
            return;
        }
        
        try {
            // Check if message has required fields
            if ($this->config['strict_mode']) {
                $this->validateRequiredFields($message);
            }
            
            // Additional validation can be added here
            
        } catch (\Throwable $e) {
            throw new GrpcException("Message validation failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Validate required fields in message
     */
    private function validateRequiredFields(Message $message): void
    {
        if (!$this->config['enable_reflection']) {
            return;
        }
        
        try {
            $reflection = new \ReflectionClass($message);
            
            // This is a simplified validation - in a real implementation,
            // you would use protobuf descriptor information
            foreach ($reflection->getMethods() as $method) {
                if (str_starts_with($method->getName(), 'get') && 
                    !str_starts_with($method->getName(), 'get_')) {
                    
                    $fieldName = lcfirst(substr($method->getName(), 3));
                    
                    // Check if field has a value (simplified check)
                    $value = $method->invoke($message);
                    
                    if ($value === null || $value === '' || $value === 0) {
                        $this->logger->debug('Empty field detected', [
                            'field' => $fieldName,
                            'message_class' => get_class($message)
                        ]);
                    }
                }
            }
            
        } catch (\Throwable $e) {
            $this->logger->warning('Required field validation failed', [
                'message_class' => get_class($message),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Generate cache key for serialization
     */
    private function generateSerializationCacheKey(Message $message): string
    {
        $class = get_class($message);
        $hash = hash('xxh3', $message->serializeToString());
        return "ser_{$class}_{$hash}";
    }

    /**
     * Generate cache key for deserialization
     */
    private function generateDeserializationCacheKey(string $data, string $messageClass): string
    {
        $hash = hash('xxh3', $data);
        return "deser_{$messageClass}_{$hash}";
    }

    /**
     * Cache serialization result
     */
    private function cacheSerializationResult(string $key, string $data): void
    {
        $this->manageCacheSize();
        
        $this->serializationCache[$key] = [
            'data' => $data,
            'timestamp' => time(),
            'size' => strlen($data)
        ];
    }

    /**
     * Cache deserialization result
     */
    private function cacheDeserializationResult(string $key, Message $message): void
    {
        $this->manageCacheSize();
        
        $this->serializationCache[$key] = [
            'data' => clone $message,
            'timestamp' => time(),
            'size' => strlen($message->serializeToString())
        ];
    }

    /**
     * Manage cache size by removing old entries
     */
    private function manageCacheSize(): void
    {
        if (count($this->serializationCache) >= $this->config['cache_max_size']) {
            // Remove oldest entries (LRU-like behavior)
            $sorted = $this->serializationCache;
            uasort($sorted, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
            
            $toRemove = array_slice(array_keys($sorted), 0, 100, true);
            foreach ($toRemove as $key) {
                unset($this->serializationCache[$key]);
            }
        }
        
        // Remove expired entries
        $now = time();
        foreach ($this->serializationCache as $key => $entry) {
            if ($now - $entry['timestamp'] > $this->config['cache_ttl']) {
                unset($this->serializationCache[$key]);
            }
        }
    }

    /**
     * Update serialization time statistics
     */
    private function updateSerializationTime(float $startTime): void
    {
        $time = microtime(true) - $startTime;
        $alpha = 0.1; // Exponential moving average factor
        $this->stats['avg_serialization_time'] = ($alpha * $time) + 
            ((1 - $alpha) * $this->stats['avg_serialization_time']);
    }

    /**
     * Update deserialization time statistics
     */
    private function updateDeserializationTime(float $startTime): void
    {
        $time = microtime(true) - $startTime;
        $alpha = 0.1; // Exponential moving average factor
        $this->stats['avg_deserialization_time'] = ($alpha * $time) + 
            ((1 - $alpha) * $this->stats['avg_deserialization_time']);
    }

    /**
     * Get serializer statistics
     */
    public function getStats(): array
    {
        $totalOperations = $this->stats['serializations'] + $this->stats['deserializations'];
        $totalCacheAccess = $this->stats['cache_hits'] + $this->stats['cache_misses'];
        
        return array_merge($this->stats, [
            'cache_hit_rate' => $totalCacheAccess > 0 ? 
                ($this->stats['cache_hits'] / $totalCacheAccess) * 100 : 0,
            'cache_size' => count($this->serializationCache),
            'class_cache_size' => count($this->messageClassCache),
            'avg_bytes_per_operation' => $totalOperations > 0 ? 
                $this->stats['total_bytes_processed'] / $totalOperations : 0,
            'config' => $this->config
        ]);
    }

    /**
     * Clear all caches
     */
    public function clearCache(): void
    {
        $this->serializationCache = [];
        $this->messageClassCache = [];
        
        $this->logger->info('Protobuf serializer cache cleared');
    }

    /**
     * Check if serializer is ready
     */
    public function isReady(): bool
    {
        return true; // Serializer is always ready
    }

    /**
     * Warm up serializer (preload common classes)
     */
    public function warmUp(array $messageClasses = []): void
    {
        foreach ($messageClasses as $messageClass) {
            $this->isValidMessageClass($messageClass);
        }
        
        $this->logger->debug('Protobuf serializer warmed up', [
            'preloaded_classes' => count($messageClasses)
        ]);
    }
}