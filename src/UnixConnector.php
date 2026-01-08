<?php

declare(strict_types=1);

namespace Hibla\Socket;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Socket\Exceptions\ConnectionFailedException;
use Hibla\Socket\Exceptions\InvalidUriException;
use Hibla\Socket\Interfaces\ConnectorInterface;

final class UnixConnector implements ConnectorInterface
{
    public function connect(string $path): PromiseInterface
    {
        if (!str_contains($path, '://')) {
            $path = 'unix://' . $path;
        } elseif (!str_starts_with($path, 'unix://')) {
            throw new InvalidUriException(
                \sprintf('Given URI "%s" is invalid (expected format: unix:///path/to/socket)', $path),
                \defined('SOCKET_EINVAL') ? SOCKET_EINVAL : (\defined('PCNTL_EINVAL') ? PCNTL_EINVAL : 22)
            );
        }

        $socketPath = substr($path, 7);
        
        if (!file_exists($socketPath)) {
            return Promise::rejected(new ConnectionFailedException(
                \sprintf('Unix socket "%s" does not exist', $socketPath),
                \defined('SOCKET_ENOENT') ? SOCKET_ENOENT : 2
            ));
        }

        if (!is_readable($socketPath) || filetype($socketPath) !== 'socket') {
            return Promise::rejected(new ConnectionFailedException(
                \sprintf('Path "%s" is not a valid Unix domain socket', $socketPath),
                \defined('SOCKET_ENOTSOCK') ? SOCKET_ENOTSOCK : 88
            ));
        }

        $resource = @stream_socket_client($path, $errno, $errstr, 1.0);

        if ($resource === false) {
            return Promise::rejected(new ConnectionFailedException(
                
                \sprintf('Unable to connect to unix domain socket "%s": %s', $socketPath, $errstr),
                $errno
            ));
        }

        $connection = new Connection($resource, isUnix: true);

        return Promise::resolved($connection);
    }
}