<?php

declare(strict_types=1);

namespace Hibla\Socket\Tests\Mocks;

use Hibla\EventLoop\Loop;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Socket\Interfaces\ConnectorInterface;
use Hibla\Socket\Interfaces\ConnectionInterface;

class MockConnector implements ConnectorInterface
{
    private ?float $delay = null;
    private ?ConnectionInterface $connection = null;
    private ?\Throwable $error = null;
    private bool $shouldHang = false;
    public bool $connectCalled = false;
    public ?string $lastUri = null;
    
    public function setSuccessAfter(float $delay, ConnectionInterface $connection): self
    {
        $this->delay = $delay;
        $this->connection = $connection;
        $this->error = null;
        $this->shouldHang = false;
        return $this;
    }
    
    public function setFailureAfter(float $delay, \Throwable $error): self
    {
        $this->delay = $delay;
        $this->error = $error;
        $this->connection = null;
        $this->shouldHang = false;
        return $this;
    }
    
    public function setHang(): self
    {
        $this->shouldHang = true;
        $this->delay = null;
        return $this;
    }
    
    public function setImmediateSuccess(ConnectionInterface $connection): self
    {
        $this->connection = $connection;
        $this->delay = null;
        $this->error = null;
        $this->shouldHang = false;
        return $this;
    }
    
    public function setImmediateFailure(\Throwable $error): self
    {
        $this->error = $error;
        $this->delay = null;
        $this->connection = null;
        $this->shouldHang = false;
        return $this;
    }

    public function connect(string $uri): PromiseInterface
    {
        $this->connectCalled = true;
        $this->lastUri = $uri;
        
        if ($this->shouldHang) {
            // Return a promise that never resolves
            return new Promise();
        }
        
        if ($this->delay === null) {
            // Immediate resolution or rejection
            if ($this->connection !== null) {
                return Promise::resolved($this->connection);
            }
            if ($this->error !== null) {
                return Promise::rejected($this->error);
            }
        }
        
        // Delayed resolution or rejection
        $promise = new Promise();
        
        Loop::addTimer($this->delay, function () use ($promise): void {
            if ($promise->isCancelled()) {
                return;
            }
            
            if ($this->connection !== null) {
                $promise->resolve($this->connection);
            } elseif ($this->error !== null) {
                $promise->reject($this->error);
            }
        });
        
        return $promise;
    }
    
    public function reset(): void
    {
        $this->delay = null;
        $this->connection = null;
        $this->error = null;
        $this->shouldHang = false;
        $this->connectCalled = false;
        $this->lastUri = null;
    }
}