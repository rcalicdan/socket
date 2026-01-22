<?php

declare(strict_types=1);

namespace Hibla\Socket\Interfaces;

use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Defines the contract for connectors capable of establishing client-side streaming connections.
 *
 * Implementations of this interface are responsible for initiating and negotiating
 * the connection process (e.g., TCP handshake, TLS handshake) asynchronously.
 * It acts as a factory for creating {@see ConnectionInterface} instances.
 */
interface ConnectorInterface
{
    /**
     * Asynchronously establishes a streaming connection to the given remote address.
     *
     * The URI must contain the scheme, host (or path), and port (if applicable).
     * Common examples include:
     * - TCP: `tcp://127.0.0.1:8080` or `tcp://example.com:80`
     * - TLS: `tls://example.com:443`
     * - Unix Domain Socket: `unix:///var/run/mysocket.sock`
     *
     * @param string $uri The resource URI to connect to.
     *
     * @return PromiseInterface<ConnectionInterface> A promise that:
     *                                               - Resolves with a {@see ConnectionInterface} on success.
     *                                               - Rejects with an exception (e.g., ConnectionFailedException,
     *                                                 InvalidUriException, or RuntimeException) on failure.
     */
    public function connect(string $uri): PromiseInterface;
}
