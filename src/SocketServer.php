<?php

declare(strict_types=1);

namespace Hibla\Socket;

use Evenement\EventEmitter;
use Hibla\Socket\Exceptions\InvalidUriException;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Socket\Interfaces\ServerInterface;
use Throwable;


final class SocketServer extends EventEmitter implements ServerInterface
{
    private readonly ServerInterface $server;

    /**
     * @param string $uri 
     * @param array $context
     */
    public function __construct(string $uri, private readonly array $context = [])
    {
        $context = $context + [
            'tcp' => [],
            'unix' => [],
            'tls' => [],
        ];

        $scheme = 'tcp';
        if (str_contains($uri, '://')) {
            $scheme = substr($uri, 0, strpos($uri, '://'));
        } elseif (is_numeric($uri)) {
            $uri = '127.0.0.1:' . $uri;
        }

        $this->server = match ($scheme) {
            'unix' => new UnixServer($uri, $context['unix']),
            'php'  => new FdServer($uri),
            'tcp'  => new TcpServer($uri, $context['tcp']),
            'tls'  => $this->createSecureServer($uri, $context),
            default => $this->createDefaultServer($uri, $context),
        };

        $this->server->on('connection', fn(ConnectionInterface $conn) => $this->emit('connection', [$conn]));
        $this->server->on('error', fn(Throwable $error) => $this->emit('error', [$error]));
    }

    private function createSecureServer(string $uri, array $context): ServerInterface
    {
        // 1. Convert "tls://host:port" to "tcp://host:port" for the underlying socket
        $tcpUri = str_replace('tls://', 'tcp://', $uri);

        // 2. Create the raw TCP server
        $tcpServer = new TcpServer($tcpUri, $context['tcp']);

        // 3. Wrap it in the SecureServer decorator using the 'tls' context
        return new SecureServer($tcpServer, $context['tls']);
    }

    private function createDefaultServer(string $uri, array $context): ServerInterface
    {
        if (preg_match('#^(?:\w+://)?\d+$#', $uri)) {
            throw new InvalidUriException(
                \sprintf('Invalid URI "%s" given (EINVAL)', $uri),
                \defined('SOCKET_EINVAL') ? SOCKET_EINVAL : (\defined('PCNTL_EINVAL') ? PCNTL_EINVAL : 22)
            );
        }

        return new TcpServer($uri, $context['tcp']);
    }

    public function getAddress(): ?string
    {
        return $this->server->getAddress();
    }

    public function pause(): void
    {
        $this->server->pause();
    }

    public function resume(): void
    {
        $this->server->resume();
    }

    public function close(): void
    {
        $this->server->close();
        $this->removeAllListeners();
    }
}