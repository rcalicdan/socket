<?php

declare(strict_types=1);

namespace Tests\Mocks;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Socket\Interfaces\ConnectorInterface;

/**
 * Mock connector that can track connection attempts and simulate different responses per URI
 */
class MockConnectorWithFailureTracking implements ConnectorInterface
{
    public array $connectionAttempts = [];
    public ?string $lastUri = null;

    private array $successByUri = [];
    private array $failureByUri = [];
    private array $hangByUri = [];
    private ?\Throwable $defaultFailure = null;
    private ?ConnectionInterface $defaultSuccess = null;
    private bool $hangAll = false;

    /**
     * Set a default successful connection for all URIs
     */
    public function setImmediateSuccess(ConnectionInterface $connection): self
    {
        $this->defaultSuccess = $connection;
        $this->defaultFailure = null;
        $this->hangAll = false;

        return $this;
    }

    /**
     * Set a default failure for all URIs
     */
    public function setImmediateFailure(\Throwable $error): self
    {
        $this->defaultFailure = $error;
        $this->defaultSuccess = null;
        $this->hangAll = false;

        return $this;
    }

    /**
     * Make all connections hang
     */
    public function setHang(): self
    {
        $this->hangAll = true;
        $this->defaultSuccess = null;
        $this->defaultFailure = null;

        return $this;
    }

    /**
     * Set successful connection for a specific URI
     */
    public function setSuccessForUri(string $uri, ConnectionInterface $connection): self
    {
        $normalized = $this->normalizeUri($uri);
        $this->successByUri[$normalized] = $connection;
        unset($this->failureByUri[$normalized]);
        unset($this->hangByUri[$normalized]);

        return $this;
    }

    /**
     * Set failure for a specific URI
     */
    public function setFailureForUri(string $uri, \Throwable $error): self
    {
        $normalized = $this->normalizeUri($uri);
        $this->failureByUri[$normalized] = $error;
        unset($this->successByUri[$normalized]);
        unset($this->hangByUri[$normalized]);

        return $this;
    }

    /**
     * Make a specific URI hang
     */
    public function setHangForUri(string $uri): self
    {
        $normalized = $this->normalizeUri($uri);
        $this->hangByUri[$normalized] = true;
        unset($this->successByUri[$normalized]);
        unset($this->failureByUri[$normalized]);

        return $this;
    }

    /**
     * Set a default failure for all URIs (fallback)
     */
    public function setFailureForAllUris(\Throwable $error): self
    {
        $this->defaultFailure = $error;
        $this->defaultSuccess = null;

        return $this;
    }

    /**
     * Connect to a URI
     */
    public function connect(string $uri): PromiseInterface
    {
        $this->connectionAttempts[] = $uri;
        $this->lastUri = $uri;
        $normalized = $this->normalizeUri($uri);

        // Check if all connections should hang
        if ($this->hangAll) {
            return new Promise();
        }

        // Check if this specific URI should hang
        if (isset($this->hangByUri[$normalized])) {
            return new Promise();
        }

        // Check for specific success
        if (isset($this->successByUri[$normalized])) {
            return Promise::resolved($this->successByUri[$normalized]);
        }

        // Check for specific failure
        if (isset($this->failureByUri[$normalized])) {
            return Promise::rejected($this->failureByUri[$normalized]);
        }

        // Default success if set
        if ($this->defaultSuccess !== null) {
            return Promise::resolved($this->defaultSuccess);
        }

        // Default failure if set
        if ($this->defaultFailure !== null) {
            return Promise::rejected($this->defaultFailure);
        }

        // Ultimate fallback: return a new mock connection
        return Promise::resolved(new MockConnection($uri));
    }

    /**
     * Normalize URI for matching (remove query parameters)
     */
    private function normalizeUri(string $uri): string
    {
        return preg_replace('/\?.*$/', '', $uri);
    }

    /**
     * Reset all state
     */
    public function reset(): void
    {
        $this->connectionAttempts = [];
        $this->lastUri = null;
        $this->successByUri = [];
        $this->failureByUri = [];
        $this->hangByUri = [];
        $this->defaultFailure = null;
        $this->defaultSuccess = null;
        $this->hangAll = false;
    }
}
