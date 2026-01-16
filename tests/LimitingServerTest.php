<?php

use Hibla\EventLoop\Loop;
use Hibla\Socket\Exceptions\ConnectionFailedException;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Socket\LimitingServer;
use Hibla\Socket\SocketServer;

describe('Limiting Server', function () {
    $server = null;
    $limitingServer = null;
    $clients = [];

    beforeEach(function () {
        if (DIRECTORY_SEPARATOR === '\\') {
            test()->markTestSkipped('Skipped on Windows');
        }

        Loop::reset();
    });

    afterEach(function () use (&$server, &$limitingServer, &$clients) {
        foreach ($clients as $client) {
            if (is_resource($client)) {
                @fclose($client);
            }
        }
        $clients = [];

        if ($limitingServer instanceof LimitingServer) {
            $limitingServer->close();
            $limitingServer = null;
        }

        if ($server instanceof SocketServer) {
            $server->close();
            $server = null;
        }

        Loop::stop();
        Loop::reset();
    });

    describe('Basic functionality', function () use (&$server, &$limitingServer, &$clients) {
        it('constructs with a connection limit', function () use (&$server, &$limitingServer) {
            $port = get_free_port();
            $server = new SocketServer('127.0.0.1:' . $port);
            $limitingServer = new LimitingServer($server, 5);

            expect($limitingServer->getAddress())->toBe('tcp://127.0.0.1:' . $port);
        });

        it('constructs with null limit for unlimited connections', function () use (&$server, &$limitingServer) {
            $port = get_free_port();
            $server = new SocketServer('127.0.0.1:' . $port);
            $limitingServer = new LimitingServer($server, null);

            expect($limitingServer->getAddress())->toBe('tcp://127.0.0.1:' . $port);
        });

        it('accepts connections up to the limit', function () use (&$server, &$limitingServer, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('127.0.0.1:' . $port);
            $limitingServer = new LimitingServer($server, 3);
            $connectionCount = 0;

            $limitingServer->on('connection', function (ConnectionInterface $connection) use (&$connectionCount) {
                $connectionCount++;
            });

            for ($i = 0; $i < 3; $i++) {
                $clients[] = @stream_socket_client($limitingServer->getAddress(), $errno, $errstr, 1);
            }

            $timeout = Loop::addTimer(0.2, function () {
                Loop::stop();
            });

            Loop::run();
            Loop::cancelTimer($timeout);

            expect($connectionCount)->toBe(3);
        });

        it('rejects connections exceeding the limit', function () use (&$server, &$limitingServer, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('127.0.0.1:' . $port);
            $limitingServer = new LimitingServer($server, 2);
            $connectionCount = 0;
            $errorCount = 0;

            $limitingServer->on('connection', function (ConnectionInterface $connection) use (&$connectionCount) {
                $connectionCount++;
            });

            $limitingServer->on('error', function ($error) use (&$errorCount) {
                if ($error instanceof ConnectionFailedException) {
                    $errorCount++;
                }
            });

            for ($i = 0; $i < 4; $i++) {
                $clients[] = @stream_socket_client($limitingServer->getAddress(), $errno, $errstr, 1);
            }

            $timeout = Loop::addTimer(0.2, function () {
                Loop::stop();
            });

            Loop::run();
            Loop::cancelTimer($timeout);

            expect($connectionCount)->toBe(2)
                ->and($errorCount)->toBe(2);
        });

        it('tracks active connections correctly', function () use (&$server, &$limitingServer, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('127.0.0.1:' . $port);
            $limitingServer = new LimitingServer($server, 5);

            $limitingServer->on('connection', function (ConnectionInterface $connection) {
                // Just accept connections
            });

            for ($i = 0; $i < 3; $i++) {
                $clients[] = @stream_socket_client($limitingServer->getAddress(), $errno, $errstr, 1);
            }

            $timeout = Loop::addTimer(0.2, function () {
                Loop::stop();
            });

            Loop::run();
            Loop::cancelTimer($timeout);

            expect(count($limitingServer->getConnections()))->toBe(3);
        });

        it('allows new connections after old ones close', function () use (&$server, &$limitingServer, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('127.0.0.1:' . $port);
            $limitingServer = new LimitingServer($server, 2);
            $connectionCount = 0;
            $connections = [];

            $limitingServer->on('connection', function (ConnectionInterface $connection) use (&$connectionCount, &$connections) {
                $connectionCount++;
                $connections[] = $connection;
            });

            for ($i = 0; $i < 2; $i++) {
                $clients[] = @stream_socket_client($limitingServer->getAddress(), $errno, $errstr, 1);
            }

            Loop::addTimer(0.1, function () use (&$clients) {
                if (is_resource($clients[0])) {
                    fclose($clients[0]);
                }
            });

            Loop::addTimer(0.2, function () use (&$limitingServer, &$clients) {
                $clients[] = @stream_socket_client($limitingServer->getAddress(), $errno, $errstr, 1);
            });

            $timeout = Loop::addTimer(0.5, function () {
                Loop::stop();
            });

            Loop::run();
            Loop::cancelTimer($timeout);

            expect($connectionCount)->toBe(3);
        });

        it('returns correct address from wrapped server', function () use (&$server, &$limitingServer) {
            $port = get_free_port();
            $server = new SocketServer('tcp://127.0.0.1:' . $port);
            $limitingServer = new LimitingServer($server, 10);

            expect($limitingServer->getAddress())->toBe($server->getAddress());
        });
    });

    describe('Pause on limit mode', function () use (&$server, &$limitingServer, &$clients) {
        it('pauses accepting connections when limit is reached', function () use (&$server, &$limitingServer, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('127.0.0.1:' . $port);
            $limitingServer = new LimitingServer($server, 2, pauseOnLimit: true);
            $connectionCount = 0;

            $limitingServer->on('connection', function (ConnectionInterface $connection) use (&$connectionCount) {
                $connectionCount++;
            });

            for ($i = 0; $i < 2; $i++) {
                $clients[] = @stream_socket_client($limitingServer->getAddress(), $errno, $errstr, 1);
            }

            Loop::addTimer(0.1, function () use (&$limitingServer, &$clients) {
                $clients[] = @stream_socket_client($limitingServer->getAddress(), $errno, $errstr, 1);
            });

            $timeout = Loop::addTimer(0.3, function () {
                Loop::stop();
            });

            Loop::run();
            Loop::cancelTimer($timeout);

            expect($connectionCount)->toBe(2);
        });

        it('resumes accepting after connection closes in pause mode', function () use (&$server, &$limitingServer, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('127.0.0.1:' . $port);
            $limitingServer = new LimitingServer($server, 2, pauseOnLimit: true);
            $connectionCount = 0;

            $limitingServer->on('connection', function (ConnectionInterface $connection) use (&$connectionCount) {
                $connectionCount++;
            });

            for ($i = 0; $i < 2; $i++) {
                $clients[] = @stream_socket_client($limitingServer->getAddress(), $errno, $errstr, 1);
            }

            Loop::addTimer(0.1, function () use (&$limitingServer, &$clients) {
                $clients[] = @stream_socket_client($limitingServer->getAddress(), $errno, $errstr, 1);
            });

            Loop::addTimer(0.2, function () use (&$clients) {
                if (is_resource($clients[0])) {
                    fclose($clients[0]);
                }
            });

            $timeout = Loop::addTimer(0.5, function () {
                Loop::stop();
            });

            Loop::run();
            Loop::cancelTimer($timeout);

            expect($connectionCount)->toBe(3);
        });

        it('does not emit error events in pause mode', function () use (&$server, &$limitingServer, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('127.0.0.1:' . $port);
            $limitingServer = new LimitingServer($server, 2, pauseOnLimit: true);
            $errorCount = 0;

            $limitingServer->on('connection', function (ConnectionInterface $connection) {
                // Just accept
            });

            $limitingServer->on('error', function ($error) use (&$errorCount) {
                $errorCount++;
            });

            for ($i = 0; $i < 3; $i++) {
                $clients[] = @stream_socket_client($limitingServer->getAddress(), $errno, $errstr, 1);
            }

            $timeout = Loop::addTimer(0.3, function () {
                Loop::stop();
            });

            Loop::run();
            Loop::cancelTimer($timeout);

            expect($errorCount)->toBe(0);
        });
    });

    describe('Manual pause and resume', function () use (&$server, &$limitingServer, &$clients) {
        it('pauses and resumes manually', function () use (&$server, &$limitingServer, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('127.0.0.1:' . $port);
            $limitingServer = new LimitingServer($server, 10);
            $connectionCount = 0;

            $limitingServer->on('connection', function (ConnectionInterface $connection) use (&$connectionCount) {
                $connectionCount++;
                Loop::stop();
            });

            $limitingServer->pause();

            $clients[] = @stream_socket_client($limitingServer->getAddress(), $errno, $errstr, 1);

            Loop::addTimer(0.1, function () use (&$connectionCount, &$limitingServer) {
                expect($connectionCount)->toBe(0);
                $limitingServer->resume();
            });

            $timeout = Loop::addTimer(0.5, function () {
                Loop::stop();
            });

            Loop::run();
            Loop::cancelTimer($timeout);

            expect($connectionCount)->toBe(1);
        });

        it('handles manual pause with auto-pause from limit', function () use (&$server, &$limitingServer, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('127.0.0.1:' . $port);
            $limitingServer = new LimitingServer($server, 2, pauseOnLimit: true);
            $connectionCount = 0;

            $limitingServer->on('connection', function (ConnectionInterface $connection) use (&$connectionCount) {
                $connectionCount++;
            });

            $limitingServer->pause();

            $clients[] = @stream_socket_client($limitingServer->getAddress(), $errno, $errstr, 1);

            Loop::addTimer(0.1, function () use (&$limitingServer) {
                $limitingServer->resume();
            });

            $timeout = Loop::addTimer(0.3, function () {
                Loop::stop();
            });

            Loop::run();
            Loop::cancelTimer($timeout);

            expect($connectionCount)->toBe(1);
        });

        it('handles multiple pause/resume cycles', function () use (&$server, &$limitingServer, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('127.0.0.1:' . $port);
            $limitingServer = new LimitingServer($server, 10);

            $limitingServer->pause();
            $limitingServer->pause(); 
            $limitingServer->resume();
            $limitingServer->resume();

            $connectionReceived = false;

            $limitingServer->on('connection', function (ConnectionInterface $connection) use (&$connectionReceived) {
                $connectionReceived = true;
                Loop::stop();
            });

            $clients[] = @stream_socket_client($limitingServer->getAddress(), $errno, $errstr, 1);

            $timeout = Loop::addTimer(0.5, function () {
                Loop::stop();
            });

            Loop::run();
            Loop::cancelTimer($timeout);

            expect($connectionReceived)->toBeTrue();
        });
    });

    describe('Connection tracking', function () use (&$server, &$limitingServer, &$clients) {
        it('removes connections from tracking when they close', function () use (&$server, &$limitingServer, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('127.0.0.1:' . $port);
            $limitingServer = new LimitingServer($server, 5);

            $limitingServer->on('connection', function (ConnectionInterface $connection) {
                // Just accept
            });
            for ($i = 0; $i < 3; $i++) {
                $clients[] = @stream_socket_client($limitingServer->getAddress(), $errno, $errstr, 1);
            }

            Loop::addTimer(0.1, function () use (&$limitingServer) {
                expect(count($limitingServer->getConnections()))->toBe(3);
            });

            Loop::addTimer(0.2, function () use (&$clients) {
                foreach ($clients as $client) {
                    if (is_resource($client)) {
                        fclose($client);
                    }
                }
            });

            Loop::addTimer(0.4, function () use (&$limitingServer) {
                expect(count($limitingServer->getConnections()))->toBe(0);
                Loop::stop();
            });

            $timeout = Loop::addTimer(1.0, function () {
                Loop::stop();
            });

            Loop::run();
            Loop::cancelTimer($timeout);
        });

        it('can iterate over active connections', function () use (&$server, &$limitingServer, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('127.0.0.1:' . $port);
            $limitingServer = new LimitingServer($server, 5);

            $limitingServer->on('connection', function (ConnectionInterface $connection) {
                // Just accept
            });

            for ($i = 0; $i < 3; $i++) {
                $clients[] = @stream_socket_client($limitingServer->getAddress(), $errno, $errstr, 1);
            }

            Loop::addTimer(0.2, function () use (&$limitingServer) {
                $count = 0;
                foreach ($limitingServer->getConnections() as $connection) {
                    expect($connection)->toBeInstanceOf(ConnectionInterface::class);
                    $count++;
                }
                expect($count)->toBe(3);
                Loop::stop();
            });

            $timeout = Loop::addTimer(1.0, function () {
                Loop::stop();
            });

            Loop::run();
            Loop::cancelTimer($timeout);
        });

        it('can broadcast to all active connections', function () use (&$server, &$limitingServer, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('127.0.0.1:' . $port);
            $limitingServer = new LimitingServer($server, 5);

            $limitingServer->on('connection', function (ConnectionInterface $connection) {
                // Just accept
            });

            for ($i = 0; $i < 3; $i++) {
                $client = @stream_socket_client($limitingServer->getAddress(), $errno, $errstr, 1);
                if ($client !== false) {
                    stream_set_blocking($client, false);
                    $clients[] = $client;
                }
            }

            Loop::addTimer(0.2, function () use (&$limitingServer) {
                foreach ($limitingServer->getConnections() as $connection) {
                    $connection->write("Hello!\n");
                }
            });

            Loop::addTimer(0.4, function () use (&$clients) {
                $receivedCount = 0;
                foreach ($clients as $client) {
                    if (is_resource($client)) {
                        $data = fread($client, 1024);
                        if ($data === "Hello!\n") {
                            $receivedCount++;
                        }
                    }
                }
                expect($receivedCount)->toBe(3);
                Loop::stop();
            });

            $timeout = Loop::addTimer(1.0, function () {
                Loop::stop();
            });

            Loop::run();
            Loop::cancelTimer($timeout);
        });
    });

    describe('Error handling', function () use (&$server, &$limitingServer, &$clients) {
        it('emits ConnectionFailedException when limit exceeded', function () use (&$server, &$limitingServer, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('127.0.0.1:' . $port);
            $limitingServer = new LimitingServer($server, 1);
            $errorReceived = null;

            $limitingServer->on('connection', function (ConnectionInterface $connection) {
                // Just accept
            });

            $limitingServer->on('error', function ($error) use (&$errorReceived) {
                $errorReceived = $error;
            });

            $clients[] = @stream_socket_client($limitingServer->getAddress(), $errno, $errstr, 1);
            $clients[] = @stream_socket_client($limitingServer->getAddress(), $errno, $errstr, 1);

            $timeout = Loop::addTimer(0.2, function () {
                Loop::stop();
            });

            Loop::run();
            Loop::cancelTimer($timeout);

            expect($errorReceived)->toBeInstanceOf(ConnectionFailedException::class)
                ->and($errorReceived->getMessage())->toContain('connection limit');
        });

        it('forwards errors from underlying server', function () use (&$server, &$limitingServer) {
            $port = get_free_port();
            $server = new SocketServer('127.0.0.1:' . $port);
            $limitingServer = new LimitingServer($server, 10);
            $errorReceived = null;

            $limitingServer->on('error', function ($error) use (&$errorReceived) {
                $errorReceived = $error;
            });

            (function () {
                $this->server->emit('error', [new RuntimeException('Test error')]);
            })->call($limitingServer);

            expect($errorReceived)->toBeInstanceOf(RuntimeException::class)
                ->and($errorReceived->getMessage())->toBe('Test error');
        });
    });

    describe('Server operations', function () use (&$server, &$limitingServer, &$clients) {
        it('closes the server and stops listening', function () use (&$server, &$limitingServer) {
            $port = get_free_port();
            $server = new SocketServer('127.0.0.1:' . $port);
            $limitingServer = new LimitingServer($server, 10);

            expect($limitingServer->getAddress())->not->toBeNull();

            $limitingServer->close();

            expect($limitingServer->getAddress())->toBeNull();
        });

        it('returns null address after close', function () use (&$server, &$limitingServer) {
            $port = get_free_port();
            $server = new SocketServer('127.0.0.1:' . $port);
            $limitingServer = new LimitingServer($server, 5);

            $limitingServer->close();

            expect($limitingServer->getAddress())->toBeNull();
        });

        it('handles close after close (idempotent)', function () use (&$server, &$limitingServer) {
            $port = get_free_port();
            $server = new SocketServer('127.0.0.1:' . $port);
            $limitingServer = new LimitingServer($server, 5);

            $limitingServer->close();
            $limitingServer->close();
            $limitingServer->close();

            expect($limitingServer->getAddress())->toBeNull();
        });
    });

    describe('Stress testing', function () use (&$server, &$limitingServer, &$clients) {
        it('handles rapid connections at limit', function () use (&$server, &$limitingServer, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('127.0.0.1:' . $port);
            $limitingServer = new LimitingServer($server, 5);
            $connectionCount = 0;
            $errorCount = 0;

            $limitingServer->on('connection', function (ConnectionInterface $connection) use (&$connectionCount) {
                $connectionCount++;
            });

            $limitingServer->on('error', function ($error) use (&$errorCount) {
                if ($error instanceof ConnectionFailedException) {
                    $errorCount++;
                }
            });

            for ($i = 0; $i < 20; $i++) {
                $clients[] = @stream_socket_client($limitingServer->getAddress(), $errno, $errstr, 1);
            }

            $timeout = Loop::addTimer(0.5, function () {
                Loop::stop();
            });

            Loop::run();
            Loop::cancelTimer($timeout);

            expect($connectionCount)->toBe(5)
                ->and($errorCount)->toBe(15);
        });

        it('maintains stability with rapid connect/disconnect cycles', function () use (&$server, &$limitingServer, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('127.0.0.1:' . $port);
            $limitingServer = new LimitingServer($server, 3);
            $totalConnections = 0;

            $limitingServer->on('connection', function (ConnectionInterface $connection) use (&$totalConnections) {
                $totalConnections++;
            });

            for ($i = 0; $i < 10; $i++) {
                Loop::addTimer(0.05 * $i, function () use (&$limitingServer, &$clients) {
                    $client = @stream_socket_client($limitingServer->getAddress(), $errno, $errstr, 1);
                    if ($client !== false) {
                        $clients[] = $client;
                    }
                });

                Loop::addTimer(0.05 * $i + 0.02, function () use (&$clients) {
                    if (!empty($clients)) {
                        $client = array_pop($clients);
                        if (is_resource($client)) {
                            fclose($client);
                        }
                    }
                });
            }

            $timeout = Loop::addTimer(1.0, function () {
                Loop::stop();
            });

            Loop::run();
            Loop::cancelTimer($timeout);

            expect($totalConnections)->toBeGreaterThan(0);
        });
    });

    describe('Null limit behavior', function () use (&$server, &$limitingServer, &$clients) {
        it('accepts unlimited connections with null limit', function () use (&$server, &$limitingServer, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('127.0.0.1:' . $port);
            $limitingServer = new LimitingServer($server, null);
            $connectionCount = 0;

            $limitingServer->on('connection', function (ConnectionInterface $connection) use (&$connectionCount) {
                $connectionCount++;
            });

            for ($i = 0; $i < 20; $i++) {
                $clients[] = @stream_socket_client($limitingServer->getAddress(), $errno, $errstr, 1);
            }

            $timeout = Loop::addTimer(0.5, function () {
                Loop::stop();
            });

            Loop::run();
            Loop::cancelTimer($timeout);

            expect($connectionCount)->toBe(20);
        });

        it('does not emit errors with null limit', function () use (&$server, &$limitingServer, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('127.0.0.1:' . $port);
            $limitingServer = new LimitingServer($server, null);
            $errorCount = 0;

            $limitingServer->on('connection', function (ConnectionInterface $connection) {
                // Just accept
            });

            $limitingServer->on('error', function ($error) use (&$errorCount) {
                $errorCount++;
            });

            for ($i = 0; $i < 15; $i++) {
                $clients[] = @stream_socket_client($limitingServer->getAddress(), $errno, $errstr, 1);
            }

            $timeout = Loop::addTimer(0.5, function () {
                Loop::stop();
            });

            Loop::run();
            Loop::cancelTimer($timeout);

            expect($errorCount)->toBe(0);
        });

        it('tracks all connections with null limit', function () use (&$server, &$limitingServer, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('127.0.0.1:' . $port);
            $limitingServer = new LimitingServer($server, null);

            $limitingServer->on('connection', function (ConnectionInterface $connection) {
                // Just accept
            });

            for ($i = 0; $i < 10; $i++) {
                $clients[] = @stream_socket_client($limitingServer->getAddress(), $errno, $errstr, 1);
            }

            Loop::addTimer(0.2, function () use (&$limitingServer) {
                expect(count($limitingServer->getConnections()))->toBe(10);
                Loop::stop();
            });

            $timeout = Loop::addTimer(1.0, function () {
                Loop::stop();
            });

            Loop::run();
            Loop::cancelTimer($timeout);
        });
    });
});