<?php

declare(strict_types=1);

namespace Hibla\Socket\Tests\Mocks;

use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Stream\Interfaces\WritableStreamInterface;

/**
 * Mock connection for testing purposes.
 * 
 * Provides a simple, non-functional implementation of ConnectionInterface
 * suitable for testing connector behavior.
 */
class MockConnection implements ConnectionInterface
{
    private bool $readable = true;
    private bool $writable = true;
    private bool $closed = false;
    
    public function __construct(
        private readonly string $remoteAddress = 'tcp://127.0.0.1:8080',
        private readonly string $localAddress = 'tcp://127.0.0.1:12345'
    ) {
    }
    
    public function isReadable(): bool
    {
        return $this->readable && !$this->closed;
    }
    
    public function isWritable(): bool
    {
        return $this->writable && !$this->closed;
    }
    
    public function pause(): void
    {
        // No-op for mock
    }
    
    public function resume(): void
    {
        // No-op for mock
    }
    
    public function pipe(WritableStreamInterface $destination, array $options = []): WritableStreamInterface
    {
        return $destination;
    }
    
    public function write(string $data): bool
    {
        return $this->writable && !$this->closed;
    }
    
    public function end(?string $data = null): void
    {
        $this->writable = false;
    }
    
    public function close(): void
    {
        $this->closed = true;
        $this->readable = false;
        $this->writable = false;
    }
    
    public function getRemoteAddress(): ?string
    {
        return $this->closed ? null : $this->remoteAddress;
    }
    
    public function getLocalAddress(): ?string
    {
        return $this->closed ? null : $this->localAddress;
    }
    
    public function on(string $event, callable $listener): void
    {
        // No-op for mock
    }
    
    public function once(string $event, callable $listener): void
    {
        // No-op for mock
    }
    
    public function removeListener(string $event, callable $listener): void
    {
        // No-op for mock
    }
    
    public function removeAllListeners(?string $event = null): void
    {
        // No-op for mock
    }
    
    public function listeners(string $event): array
    {
        return [];
    }
    
    public function emit(string $event, array $arguments = []): void
    {
        // No-op for mock
    }
    
    public function isClosed(): bool
    {
        return $this->closed;
    }
}