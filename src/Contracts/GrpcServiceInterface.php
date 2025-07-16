<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\GRPC\Contracts;

/**
 * gRPC Service Interface Contract
 * 
 * Defines the contract for all gRPC services in the HighPer framework.
 * Services implementing this interface can be registered with the gRPC server
 * and will be automatically discovered and handled.
 */
interface GrpcServiceInterface
{
    /**
     * Get service name (used for routing)
     */
    public function getServiceName(): string;

    /**
     * Get service version
     */
    public function getServiceVersion(): string;

    /**
     * Get available methods for this service
     * 
     * @return array<string, array{
     *     request_type: string,
     *     response_type: string,
     *     streaming: bool,
     *     client_streaming: bool,
     *     server_streaming: bool
     * }>
     */
    public function getMethods(): array;

    /**
     * Check if service is healthy and ready to serve requests
     */
    public function isHealthy(): bool;

    /**
     * Get service metadata
     */
    public function getMetadata(): array;

    /**
     * Called when service is registered with gRPC server
     */
    public function onRegister(): void;

    /**
     * Called when service is unregistered from gRPC server
     */
    public function onUnregister(): void;

    /**
     * Called before processing a request
     */
    public function beforeRequest(string $method, object $request): void;

    /**
     * Called after processing a request
     */
    public function afterRequest(string $method, object $request, object $response): void;

    /**
     * Called when an error occurs during request processing
     */
    public function onError(string $method, object $request, \Throwable $error): void;
}