<?php

use Hibla\EventLoop\Loop;
use Hibla\Socket\Exceptions\EncryptionFailedException;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Socket\SecureServer;
use Hibla\Socket\TcpServer;

describe('Secure Server', function () {
    $server = null;
    $clients = [];
    $certFile = null;

    beforeEach(function () use (&$certFile) {
        if (DIRECTORY_SEPARATOR === '\\') {
            test()->markTestSkipped('Skipped on Windows');
        }

        $certFile = generate_temp_cert();
    });

    afterEach(function () use (&$server, &$clients, &$certFile) {
        foreach ($clients as $client) {
            if (is_resource($client)) {
                @fclose($client);
            }
        }
        $clients = [];

        if ($server instanceof SecureServer) {
            $server->close();
            $server = null;
        }

        if ($certFile && file_exists($certFile)) {
            @unlink($certFile);
        }
    });

    it('wraps a TCP server and returns TLS address', function () use (&$server, &$certFile) {
        $port = get_free_port();

        $tcpServer = new TcpServer('127.0.0.1:' . $port);
        $server = new SecureServer($tcpServer, [
            'local_cert' => $certFile,
        ]);

        $address = $server->getAddress();

        expect($address)->toBe('tls://127.0.0.1:' . $port);
    });

    it('returns null address when underlying server is closed', function () use (&$server, &$certFile) {
        $port = get_free_port();

        $tcpServer = new TcpServer('127.0.0.1:' . $port);
        $server = new SecureServer($tcpServer, [
            'local_cert' => $certFile,
        ]);

        $server->close();
        $address = $server->getAddress();

        expect($address)->toBeNull();
    });

    it('accepts encrypted connection and emits connection event', function () use (&$server, &$clients, &$certFile) {
        $port = get_free_port();

        $tcpServer = new TcpServer('127.0.0.1:' . $port);
        $server = new SecureServer($tcpServer, ['local_cert' => $certFile]);

        $connectionReceived = null;

        $server->on('connection', function (ConnectionInterface $connection) use (&$connectionReceived) {
            $connectionReceived = $connection;
            Loop::stop();
        });

        $server->on('error', function ($error) {
            echo "Error: " . $error->getMessage() . "\n";
        });

        Loop::addTimer(0.05, function () use ($port, &$clients) {
            create_async_tls_client($port, $clients);
        });

        run_with_timeout(5.0);

        expect($connectionReceived)->toBeInstanceOf(ConnectionInterface::class)
            ->and($connectionReceived->getRemoteAddress())->not->toBeNull();
    });

    it('accepts multiple encrypted connections', function () use (&$server, &$clients, &$certFile) {
        $port = get_free_port();

        $tcpServer = new TcpServer('127.0.0.1:' . $port);
        $server = new SecureServer($tcpServer, [
            'local_cert' => $certFile,
        ]);

        $connectionCount = 0;

        $server->on('connection', function (ConnectionInterface $connection) use (&$connectionCount) {
            $connectionCount++;
            if ($connectionCount === 3) {
                Loop::stop();
            }
        });

        $server->on('error', function ($error) {
            echo "Error: " . $error->getMessage() . "\n";
        });

        Loop::addTimer(0.05, function () use ($port, &$clients) {
            create_async_tls_client($port, $clients);
            create_async_tls_client($port, $clients);
            create_async_tls_client($port, $clients);
        });

        run_with_timeout(5.0);

        expect($connectionCount)->toBe(3);
    });

    it('pauses and resumes accepting connections', function () use (&$server, &$clients, &$certFile) {
        $port = get_free_port();

        $tcpServer = new TcpServer('127.0.0.1:' . $port);
        $server = new SecureServer($tcpServer, [
            'local_cert' => $certFile,
        ]);

        $connectionCount = 0;

        $server->on('connection', function (ConnectionInterface $connection) use (&$connectionCount) {
            $connectionCount++;
            $connection->end();
            Loop::stop();
        });

        $server->on('error', function ($error) {
            echo "Error: " . $error->getMessage() . "\n";
        });

        $server->pause();

        Loop::addTimer(0.05, function () use ($port, &$clients) {
            create_async_tls_client($port, $clients);
        });

        Loop::addTimer(0.3, function () use ($server, &$connectionCount) {
            expect($connectionCount)->toBe(0);
            $server->resume();
        });

        run_with_timeout(5.0);

        expect($connectionCount)->toBe(1);
    });

    it('emits error when TLS handshake fails', function () use (&$server, &$clients, &$certFile) {
        $port = get_free_port();

        $tcpServer = new TcpServer('127.0.0.1:' . $port);
        $server = new SecureServer($tcpServer, [
            'local_cert' => $certFile,
        ]);

        $errorReceived = null;

        $server->on('error', function ($error) use (&$errorReceived) {
            $errorReceived = $error;
            Loop::stop();
        });

        $server->on('connection', function ($connection) {
            echo "Unexpected connection received!\n";
        });

        Loop::addTimer(0.05, function () use ($port, &$clients) {
            $client = @stream_socket_client(
                'tcp://127.0.0.1:' . $port,
                $errno,
                $errstr,
                5,
                STREAM_CLIENT_CONNECT
            );

            if ($client !== false) {
                $clients[] = $client;
                fwrite($client, "plain text\n");
            }
        });

        run_with_timeout(5.0);

        expect($errorReceived)->toBeInstanceOf(EncryptionFailedException::class);
    });

    it('can send and receive encrypted data', function () use (&$server, &$clients, &$certFile) {
        $port = get_free_port();

        $tcpServer = new TcpServer('127.0.0.1:' . $port);
        $server = new SecureServer($tcpServer, [
            'local_cert' => $certFile,
        ]);

        $dataReceived = null;
        $clientData = null;

        $server->on('connection', function (ConnectionInterface $connection) use (&$dataReceived) {
            $connection->on('data', function ($data) use (&$dataReceived, $connection) {
                $dataReceived = $data;
                $connection->write("Echo: " . $data);
            });
        });

        $server->on('error', function ($error) {
            echo "Error: " . $error->getMessage() . "\n";
        });

        Loop::addTimer(0.05, function () use ($port, &$clients, &$clientData) {
            $client = @stream_socket_client(
                'tcp://127.0.0.1:' . $port,
                $errno,
                $errstr,
                5,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
            );

            if ($client === false) {
                return;
            }

            stream_set_blocking($client, false);
            $clients[] = $client;

            stream_context_set_option($client, 'ssl', 'verify_peer', false);
            stream_context_set_option($client, 'ssl', 'verify_peer_name', false);
            stream_context_set_option($client, 'ssl', 'allow_self_signed', true);

            Loop::addTimer(0.1, function () use ($client, &$clientData) {
                $watcherId = null;
                $handshakeComplete = false;

                $enableCrypto = function () use ($client, &$watcherId, &$handshakeComplete, &$clientData) {
                    $result = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

                    if ($result === true) {
                        if ($watcherId !== null) {
                            Loop::removeReadWatcher($watcherId);
                        }
                        $handshakeComplete = true;

                        fwrite($client, "Hello Secure World\n");

                        Loop::addTimer(0.5, function () use ($client, &$clientData) {
                            $clientData = @fread($client, 1024);
                            Loop::stop();
                        });
                    } elseif ($result === false) {
                        if ($watcherId !== null) {
                            Loop::removeReadWatcher($watcherId);
                        }
                    }
                };

                $result = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

                if ($result === 0) {
                    $watcherId = Loop::addReadWatcher(
                        $client,
                        $enableCrypto
                    );
                } elseif ($result === true) {
                    $enableCrypto();
                }
            });
        });

        run_with_timeout(5.0);

        expect($dataReceived)->toBe("Hello Secure World\n")
            ->and($clientData)->toBe("Echo: Hello Secure World\n");
    });

    it('closes server and underlying TCP server', function () use (&$server, &$certFile) {
        $port = get_free_port();

        $tcpServer = new TcpServer('127.0.0.1:' . $port);
        $server = new SecureServer($tcpServer, [
            'local_cert' => $certFile,
        ]);

        $address1 = $server->getAddress();
        expect($address1)->not->toBeNull();

        $server->close();

        $address2 = $server->getAddress();
        $address3 = $tcpServer->getAddress();

        expect($address2)->toBeNull()
            ->and($address3)->toBeNull();
    });

    it('handles connection close event', function () use (&$server, &$clients, &$certFile) {
        $port = get_free_port();

        $tcpServer = new TcpServer('127.0.0.1:' . $port);
        $server = new SecureServer($tcpServer, [
            'local_cert' => $certFile,
        ]);

        $closed = false;

        $server->on('connection', function (ConnectionInterface $connection) use (&$closed) {
            $connection->on('close', function () use (&$closed) {
                $closed = true;
                Loop::stop();
            });
        });

        $server->on('error', function ($error) {
            echo "Error: " . $error->getMessage() . "\n";
        });

        $clientRef = null;

        Loop::addTimer(0.05, function () use ($port, &$clients, &$clientRef) {
            $client = @stream_socket_client(
                'tcp://127.0.0.1:' . $port,
                $errno,
                $errstr,
                5,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
            );

            if ($client === false) {
                return;
            }

            stream_set_blocking($client, false);
            $clients[] = $client;
            $clientRef = $client;

            stream_context_set_option($client, 'ssl', 'verify_peer', false);
            stream_context_set_option($client, 'ssl', 'verify_peer_name', false);
            stream_context_set_option($client, 'ssl', 'allow_self_signed', true);

            Loop::addTimer(0.1, function () use ($client) {
                $result = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

                if ($result === 0) {
                    Loop::addReadWatcher(
                        $client,
                        function () use ($client) {
                            @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                        },
                    );
                }
            });
        });

        Loop::addTimer(0.5, function () use (&$clientRef) {
            if (is_resource($clientRef)) {
                fclose($clientRef);
            }
        });

        run_with_timeout(20.0);

        expect($closed)->toBe(true);
    });

    it('supports custom SSL context options', function () use (&$server, &$certFile) {
        $port = get_free_port();

        $tcpServer = new TcpServer('127.0.0.1:' . $port);
        $server = new SecureServer($tcpServer, [
            'local_cert' => $certFile,
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]);

        $address = $server->getAddress();

        expect($address)->toBe('tls://127.0.0.1:' . $port);
    });

    it('handles IPv6 addresses', function () use (&$server, &$clients, &$certFile) {
        $socket = @stream_socket_server('tcp://[::1]:0');
        if ($socket === false) {
            test()->skip('IPv6 is not supported on this system.');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = parse_url('tcp://' . $address, PHP_URL_PORT);

        $tcpServer = new TcpServer('[::1]:' . $port);
        $server = new SecureServer($tcpServer, [
            'local_cert' => $certFile,
        ]);

        $connectionReceived = false;

        $server->on('connection', function (ConnectionInterface $connection) use (&$connectionReceived) {
            $connectionReceived = true;
            Loop::stop();
        });

        $server->on('error', function ($error) {
            echo "Error: " . $error->getMessage() . "\n";
        });

        Loop::addTimer(0.05, function () use ($port, &$clients) {
            $client = @stream_socket_client(
                'tcp://[::1]:' . $port,
                $errno,
                $errstr,
                5,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
            );

            if ($client === false) {
                return;
            }

            stream_set_blocking($client, false);
            $clients[] = $client;

            stream_context_set_option($client, 'ssl', 'verify_peer', false);
            stream_context_set_option($client, 'ssl', 'verify_peer_name', false);
            stream_context_set_option($client, 'ssl', 'allow_self_signed', true);

            Loop::addTimer(0.1, function () use ($client) {
                $result = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

                if ($result === 0) {
                    Loop::addReadWatcher(
                        $client,
                        function () use ($client) {
                            @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                        },
                    );
                }
            });
        });

        run_with_timeout(5.0);

        expect($connectionReceived)->toBeTrue();
    });

    it('emits error when base server emits error', function () use (&$server, &$certFile) {
        $port = get_free_port();

        $tcpServer = new TcpServer('127.0.0.1:' . $port);
        $server = new SecureServer($tcpServer, [
            'local_cert' => $certFile,
        ]);

        $errorReceived = null;

        $server->on('error', function ($error) use (&$errorReceived) {
            $errorReceived = $error;
        });

        $tcpServer->emit('error', [new RuntimeException('Test error')]);

        expect($errorReceived)->toBeInstanceOf(RuntimeException::class)
            ->and($errorReceived->getMessage())->toBe('Test error');
    });
});
