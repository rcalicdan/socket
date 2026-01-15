<?php

declare(strict_types=1);

namespace Hibla\Socket;

use Hibla\EventLoop\Loop;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Socket\Exceptions\TimeoutException;
use Hibla\Socket\Interfaces\ConnectorInterface;
use Throwable;

final class TimeoutConnector implements ConnectorInterface
{
    public function __construct(
        private readonly ConnectorInterface $connector,
        private readonly float $timeout
    ) {}

    public function connect(string $uri): PromiseInterface
    {
        /** @var Promise $promise */
        $promise = new Promise();
        
        $pendingConnection = $this->connector->connect($uri);
        $timerId = null;

        $cleanup = function () use (&$timerId): void {
            if ($timerId !== null) {
                Loop::cancelTimer($timerId);
                $timerId = null;
            }
        };

        $timerId = Loop::addTimer($this->timeout, function () use ($promise, $pendingConnection, $cleanup, $uri) {
            $cleanup();
            $pendingConnection->cancel();
            
            $promise->reject(new TimeoutException(
                \sprintf('Connection to %s timed out after %.2f seconds', $uri, $this->timeout),
                \defined('SOCKET_ETIMEDOUT') ? SOCKET_ETIMEDOUT : 110
            ));
        });

        $pendingConnection->then(
            function ($connection) use ($promise, $cleanup) {
                $cleanup();
                $promise->resolve($connection);
            },
            function (Throwable $e) use ($promise, $cleanup) {
                $cleanup();
                $promise->reject($e);
            }
        );

        $promise->onCancel(function () use ($pendingConnection, $cleanup) {
            $cleanup();
            $pendingConnection->cancel();
        });

        return $promise;
    }
}