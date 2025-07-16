<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\GRPC\Contracts;

/**
 * Interface for gRPC processing engines
 */
interface EngineInterface
{
    /**
     * Process a gRPC message
     */
    public function processMessage(string $message): string;

    /**
     * Serialize a message object to string
     */
    public function serializeMessage(object $message): string;

    /**
     * Deserialize a string to message object
     */
    public function deserializeMessage(string $data, string $messageClass): object;

    /**
     * Compress data using specified algorithm
     */
    public function compress(string $data, string $algorithm = 'gzip'): string;

    /**
     * Decompress data using specified algorithm
     */
    public function decompress(string $data, string $algorithm = 'gzip'): string;

    /**
     * Check if the engine is ready for processing
     */
    public function isReady(): bool;

    /**
     * Get engine statistics
     */
    public function getStats(): array;

    /**
     * Get supported compression algorithms
     */
    public function getSupportedCompressions(): array;
}