<?php

declare(strict_types=1);

namespace Hibla\Socket;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Socket\Interfaces\ConnectorInterface;

/**
 * A connector that enforces connections to a specific, pre-defined URI.
 *
 * This implementation ignores the URI passed to the {@see connect()} method and
 * always establishes a connection to the fixed URI configured during instantiation.
 *
 * This is particularly useful for scenarios such as:
 * - Service aliasing (mapping a logical name to a specific endpoint).
 * - Testing (redirecting all outgoing connections to a local mock server).
 * - Forcing traffic through a specific gateway or tunnel.
 */
final readonly class FixedUriConnector implements ConnectorInterface
{
    /**
     * Creates a new FixedUriConnector that ignores the target URI.
     *
     * @param string $uri The fixed URI to always connect to
     * @param ConnectorInterface $connector The underlying connector to use
     */
    public function __construct(
        private string $uri,
        private ConnectorInterface $connector,
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * Note: The provided URI parameter is ignored. This connector will
     * always connect to the fixed URI specified in the constructor.
     *
     * @param string $_ The target URI (ignored)
     * @return PromiseInterface<ConnectionInterface>
     */
    public function connect(string $_): PromiseInterface
    {
        return $this->connector->connect($this->uri);
    }
}
