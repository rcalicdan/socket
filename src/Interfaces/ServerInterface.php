<?php

declare(strict_types=1);

namespace Hibla\Socket\Interfaces;

use Evenement\EventEmitterInterface;

/**
 * Defines the contract for a server that listens for incoming connections.
 *
 * Implementations of this interface represent servers (e.g., TCP, Unix, TLS)
 * capable of accepting connections asynchronously. It provides control over
 * the listening state (pausing/resuming) and lifecycle management.
 *
 * @event connection (ConnectionInterface $connection) Emitted when a new connection is accepted.
 * @event error (Exception $e) Emitted when a critical error occurs (e.g., accept failure).
 * @event close () Emitted when the server is closed.
 */
interface ServerInterface extends EventEmitterInterface
{
    /**
     * Retrieves the address on which the server is currently listening.
     *
     * Returns the address in a standard URI format, such as:
     * - `tcp://127.0.0.1:8080`
     * - `unix:///var/run/app.sock`
     * - `tls://0.0.0.0:443`
     *
     * @return string|null The address string, or null if the server is closed.
     */
    public function getAddress(): ?string;

    /**
     * Temporarily suspends accepting new incoming connections.
     *
     * While paused, the server socket remains bound, but the event loop will
     * stop watching for new connection attempts. Existing connections remain active.
     *
     * @return void
     */
    public function pause(): void;

    /**
     * Resumes accepting new incoming connections.
     *
     * Re-enables the event loop watcher to accept connections after the server
     * has been paused. If the server was not paused, this method has no effect.
     *
     * @return void
     */
    public function resume(): void;

    /**
     * Shuts down the server and stops listening.
     *
     * This method unbinds the server socket, removes all event loop watchers,
     * and releases associated resources. Once closed, the server cannot be
     * resumed or reused.
     *
     * @return void
     */
    public function close(): void;
}