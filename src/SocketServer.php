<?php

declare(strict_types=1);

namespace Hibla\Socket;

use Evenement\EventEmitter;
use Hibla\Socket\Exceptions\InvalidUriException;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Socket\Interfaces\ServerInterface;
use Throwable;

/**
 * A High level and versatile facade, multiplexing server that supports various socket types (TCP, TLS, Unix).
 *
 * This class acts as a high-level server factory and facade. It inspects the provided
 * URI scheme (e.g., `tcp://`, `tls://`, `unix://`) during construction and internally
 * instantiates the appropriate concrete server implementation (e.g., TcpServer, SecureServer,
 * UnixServer).
 *
 * It forwards all control methods (pause, resume, close) and events (connection, error)
 * from the underlying specialized server, providing a single, unified interface for
 * application-level code.
 */
final class SocketServer extends EventEmitter implements ServerInterface
{
    private readonly ServerInterface $server;

    /**
     * @param string $uri
     * @param array<string, mixed> $context
     */
    public function __construct(string $uri, array $context = [])
    {
        /** @var array{tcp: array<string, mixed>, tls: array<string, mixed>, unix: array<string, mixed>} $mergedContext */
        $mergedContext = $context + [
            'tcp' => [],
            'unix' => [],
            'tls' => [],
        ];

        $scheme = 'tcp';
        if (str_contains($uri, '://')) {
            $pos = strpos($uri, '://');
            $scheme = $pos !== false ? substr($uri, 0, $pos) : 'tcp';
        } elseif (is_numeric($uri)) {
            $uri = '127.0.0.1:' . $uri;
        }

        $this->server = match ($scheme) {
            'unix' => new UnixServer($uri, $mergedContext['unix']),
            'php' => new FdServer($uri),
            'tcp' => new TcpServer($uri, $mergedContext['tcp']),
            'tls' => $this->createSecureServer($uri, $mergedContext),
            default => $this->createDefaultServer($uri, $mergedContext),
        };

        $this->server->on('connection', fn (ConnectionInterface $conn) => $this->emit('connection', [$conn]));
        $this->server->on('error', fn (Throwable $error) => $this->emit('error', [$error]));
    }

    /**
     * {@inheritDoc}
     */
    public function getAddress(): ?string
    {
        return $this->server->getAddress();
    }

    /**
     * {@inheritDoc}
     */
    public function pause(): void
    {
        $this->server->pause();
    }

    /**
     * {@inheritDoc}
     */
    public function resume(): void
    {
        $this->server->resume();
    }

    /**
     * {@inheritDoc}
     */
    public function close(): void
    {
        $this->server->close();
        $this->removeAllListeners();
    }

    /**
     * @param array{tcp: array<string, mixed>, tls: array<string, mixed>, unix: array<string, mixed>} $context
     */
    private function createSecureServer(string $uri, array $context): ServerInterface
    {
        $tcpUri = str_replace('tls://', 'tcp://', $uri);

        $tcpServer = new TcpServer($tcpUri, $context['tcp']);

        return new SecureServer($tcpServer, $context['tls']);
    }

    /**
     * @param array{tcp: array<string, mixed>, tls: array<string, mixed>, unix: array<string, mixed>} $context
     */
    private function createDefaultServer(string $uri, array $context): ServerInterface
    {
        if (preg_match('#^(?:\w+://)?\d+$#', $uri) === 1) {
            throw new InvalidUriException(
                \sprintf('Invalid URI "%s" given (EINVAL)', $uri),
                \defined('SOCKET_EINVAL') ? SOCKET_EINVAL : (\defined('PCNTL_EINVAL') ? PCNTL_EINVAL : 22)
            );
        }

        return new TcpServer($uri, $context['tcp']);
    }
}
