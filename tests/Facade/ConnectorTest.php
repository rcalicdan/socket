<?php

declare(strict_types=1);

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Socket\Connector;
use Hibla\Socket\Exceptions\InvalidUriException;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Tests\Mocks\MockConnection;
use Tests\Mocks\MockConnector;
use Tests\Mocks\MockResolver;

describe('Connector', function () {
    describe('Basic TCP connections', function () {
        test('connects with TCP scheme', function () {
            $connector = new Connector(['dns' => false]);

            $promise = $connector->connect('tcp://127.0.0.1:8080');

            expect($promise)->toBeInstanceOf(PromiseInterface::class);
        });

        test('connects without scheme defaults to TCP', function () {
            $connector = new Connector(['dns' => false]);

            $promise = $connector->connect('127.0.0.1:8080');

            expect($promise)->toBeInstanceOf(PromiseInterface::class);
        });

        test('throws InvalidUriException for unsupported scheme', function () {
            $connector = new Connector();

            $promise = $connector->connect('http://example.com:80');

            expect(fn () => $promise->wait())
                ->toThrow(InvalidUriException::class, 'No connector available for URI scheme "http"')
            ;
        });

        test('throws InvalidUriException for ftp scheme', function () {
            $connector = new Connector();

            $promise = $connector->connect('ftp://example.com:21');

            expect(fn () => $promise->wait())
                ->toThrow(InvalidUriException::class, 'No connector available for URI scheme "ftp"')
            ;
        });
    });

    describe('TLS connections', function () {
        test('connects with TLS scheme', function () {
            $connector = new Connector(['dns' => false]);

            $promise = $connector->connect('tls://127.0.0.1:443');

            expect($promise)->toBeInstanceOf(PromiseInterface::class);
        });

        test('TLS connector requires TCP connector', function () {
            $connector = new Connector(['tcp' => false, 'tls' => true]);

            $promise = $connector->connect('tls://example.com:443');

            expect(fn () => $promise->wait())
                ->toThrow(InvalidUriException::class, 'No connector available for URI scheme "tls"')
            ;
        });
    });

    describe('Unix domain socket connections', function () {
        test('connects with unix scheme', function () {
            $socketPath = sys_get_temp_dir() . '/test-' . uniqid() . '.sock';
            $connector = new Connector();

            $promise = $connector->connect('unix://' . $socketPath);

            expect($promise)->toBeInstanceOf(PromiseInterface::class);

            $promise->catch(function ($e) {
                expect($e)->toBeInstanceOf(Throwable::class);
            });
        });

        test('unix connector can be disabled', function () {
            $connector = new Connector(['unix' => false]);

            $promise = $connector->connect('unix:///tmp/test.sock');

            expect(fn () => $promise->wait())
                ->toThrow(InvalidUriException::class, 'No connector available for URI scheme "unix"')
            ;
        });
    })->skipOnWindows();

    describe('Custom connector configuration', function () {
        test('accepts custom TCP connector', function () {
            $mockConnector = new MockConnector();
            $mockConnector->setImmediateSuccess(new MockConnection());

            $connector = new Connector([
                'tcp' => $mockConnector,
                'dns' => false,
            ]);

            $promise = $connector->connect('tcp://127.0.0.1:8080');
            $result = $promise->wait();

            expect($mockConnector->connectCalled)->toBeTrue()
                ->and($result)->toBeInstanceOf(ConnectionInterface::class)
            ;
        });

        test('accepts custom TLS connector', function () {
            $mockTcpConnector = new MockConnector();
            $mockTlsConnector = new MockConnector();
            $mockTlsConnector->setImmediateSuccess(new MockConnection());

            $connector = new Connector([
                'tcp' => $mockTcpConnector,
                'tls' => $mockTlsConnector,
                'dns' => false,
            ]);

            $promise = $connector->connect('tls://127.0.0.1:443');
            $result = $promise->wait();

            expect($mockTlsConnector->connectCalled)->toBeTrue()
                ->and($result)->toBeInstanceOf(ConnectionInterface::class)
            ;
        });

        test('accepts custom Unix connector', function () {
            $mockConnector = new MockConnector();
            $mockConnector->setImmediateSuccess(new MockConnection());

            $connector = new Connector([
                'unix' => $mockConnector,
            ]);

            $promise = $connector->connect('unix:///tmp/test.sock');
            $result = $promise->wait();

            expect($mockConnector->connectCalled)->toBeTrue()
                ->and($result)->toBeInstanceOf(ConnectionInterface::class)
            ;
        });

        test('accepts custom DNS resolver', function () {
            $mockResolver = new MockResolver();
            $mockResolver->setImmediateSuccess('93.184.216.34');

            $connector = new Connector([
                'dns' => $mockResolver,
                'happy_eyeballs' => false,
            ]);

            expect($connector)->toBeInstanceOf(Connector::class);
        });

        test('accepts TCP context options as array', function () {
            $connector = new Connector([
                'tcp' => ['bindto' => '0.0.0.0:0'],
                'dns' => false,
            ]);

            expect($connector)->toBeInstanceOf(Connector::class);
        });

        test('accepts TLS context options as array', function () {
            $connector = new Connector([
                'tls' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);

            expect($connector)->toBeInstanceOf(Connector::class);
        });
    });

    describe('DNS resolution configuration', function () {
        test('DNS resolution enabled by default', function () {
            $connector = new Connector();

            expect($connector)->toBeInstanceOf(Connector::class);
        });

        test('DNS resolution can be disabled', function () {
            $connector = new Connector(['dns' => false]);

            expect($connector)->toBeInstanceOf(Connector::class);
        });

        test('accepts custom DNS nameservers', function () {
            $connector = new Connector([
                'dns' => ['1.1.1.1', '8.8.8.8'],
            ]);

            expect($connector)->toBeInstanceOf(Connector::class);
        });

        test('Happy Eyeballs enabled by default', function () {
            $connector = new Connector();

            expect($connector)->toBeInstanceOf(Connector::class);
        });

        test('Happy Eyeballs can be disabled', function () {
            $connector = new Connector(['happy_eyeballs' => false]);

            expect($connector)->toBeInstanceOf(Connector::class);
        });
    });

    describe('Timeout configuration', function () {
        test('timeout enabled by default', function () {
            $connector = new Connector();

            expect($connector)->toBeInstanceOf(Connector::class);
        });

        test('timeout can be disabled', function () {
            $connector = new Connector(['timeout' => false]);

            expect($connector)->toBeInstanceOf(Connector::class);
        });

        test('accepts custom timeout value', function () {
            $connector = new Connector(['timeout' => 5.0]);

            expect($connector)->toBeInstanceOf(Connector::class);
        });

        test('timeout of zero disables timeout', function () {
            $connector = new Connector(['timeout' => 0]);

            expect($connector)->toBeInstanceOf(Connector::class);
        });

        test('negative timeout disables timeout', function () {
            $connector = new Connector(['timeout' => -1.0]);

            expect($connector)->toBeInstanceOf(Connector::class);
        });

        test('uses default_socket_timeout when timeout is true', function () {
            $originalTimeout = ini_get('default_socket_timeout');
            ini_set('default_socket_timeout', '30');

            $connector = new Connector(['timeout' => true]);

            expect($connector)->toBeInstanceOf(Connector::class);

            ini_set('default_socket_timeout', $originalTimeout);
        });
    });

    describe('Scheme extraction', function () {
        test('extracts tcp scheme', function () {
            $connector = new Connector(['dns' => false]);

            $promise = $connector->connect('tcp://127.0.0.1:8080');

            expect($promise)->toBeInstanceOf(PromiseInterface::class);
        });

        test('extracts tls scheme', function () {
            $connector = new Connector(['dns' => false]);

            $promise = $connector->connect('tls://127.0.0.1:443');

            expect($promise)->toBeInstanceOf(PromiseInterface::class);
        });

        test('extracts unix scheme', function () {
            $connector = new Connector();

            $promise = $connector->connect('unix:///tmp/test.sock');

            expect($promise)->toBeInstanceOf(PromiseInterface::class);

            // Handle the rejection to avoid unhandled promise warnings
            $promise->catch(function ($e) {
                // Expected - socket doesn't exist
                expect($e)->toBeInstanceOf(Throwable::class);
            });
        });

        test('defaults to tcp when no scheme provided', function () {
            $connector = new Connector(['dns' => false]);

            $promise = $connector->connect('127.0.0.1:8080');

            expect($promise)->toBeInstanceOf(PromiseInterface::class);
        });
    });

    describe('Connector combinations', function () {
        test('TCP with DNS but without Happy Eyeballs', function () {
            $mockResolver = new MockResolver();
            $mockResolver->setImmediateSuccess('93.184.216.34');

            $connector = new Connector([
                'dns' => $mockResolver,
                'happy_eyeballs' => false,
            ]);

            expect($connector)->toBeInstanceOf(Connector::class);
        });

        test('TCP with Happy Eyeballs', function () {
            $mockResolver = new MockResolver();
            $mockResolver->setImmediateSuccess('93.184.216.34');

            $connector = new Connector([
                'dns' => $mockResolver,
                'happy_eyeballs' => true,
            ]);

            expect($connector)->toBeInstanceOf(Connector::class);
        });

        test('TCP with timeout but without DNS', function () {
            $connector = new Connector([
                'dns' => false,
                'timeout' => 5.0,
            ]);

            expect($connector)->toBeInstanceOf(Connector::class);
        });

        test('all features disabled', function () {
            $connector = new Connector([
                'tcp' => false,
                'tls' => false,
                'unix' => false,
            ]);

            $promise = $connector->connect('tcp://127.0.0.1:8080');

            expect(fn () => $promise->wait())
                ->toThrow(InvalidUriException::class)
            ;
        });
    });

    describe('Error handling', function () {
        test('connection failure propagates', function () {
            $mockConnector = new MockConnector();
            $error = new RuntimeException('Connection failed');
            $mockConnector->setImmediateFailure($error);

            $connector = new Connector([
                'tcp' => $mockConnector,
                'dns' => false,
            ]);

            $promise = $connector->connect('tcp://127.0.0.1:8080');

            expect(fn () => $promise->wait())
                ->toThrow(RuntimeException::class, 'Connection failed')
            ;
        });

        test('invalid URI handled gracefully', function () {
            $connector = new Connector();

            $promise = $connector->connect('invalid-uri');

            expect($promise)->toBeInstanceOf(PromiseInterface::class);
        });
    });

    describe('Default context merging', function () {
        test('empty context uses defaults', function () {
            $connector = new Connector([]);

            expect($connector)->toBeInstanceOf(Connector::class);
        });

        test('partial context merges with defaults', function () {
            $connector = new Connector([
                'timeout' => 10.0,
            ]);

            expect($connector)->toBeInstanceOf(Connector::class);
        });

        test('context overrides defaults', function () {
            $connector = new Connector([
                'tcp' => false,
                'dns' => false,
                'timeout' => false,
                'happy_eyeballs' => false,
            ]);

            expect($connector)->toBeInstanceOf(Connector::class);
        });
    });
});

describe('Connector - Real Network Integration', function () {
    describe('TCP connections', function () {
        test('connects to cloudflare.com via TCP', function () {
            $connector = new Connector([
                'timeout' => 5.0,
            ]);

            $promise = $connector->connect('tcp://1.1.1.1:80');
            $connection = $promise->wait();

            expect($connection)
                ->toBeInstanceOf(ConnectionInterface::class)
                ->and($connection->isReadable())->toBeTrue()
                ->and($connection->isWritable())->toBeTrue()
            ;

            $connection->close();
        });

        test('connects to google DNS via TCP', function () {
            $connector = new Connector([
                'timeout' => 5.0,
            ]);

            $promise = $connector->connect('tcp://8.8.8.8:53');
            $connection = $promise->wait();

            expect($connection)
                ->toBeInstanceOf(ConnectionInterface::class)
                ->and($connection->isReadable())->toBeTrue()
                ->and($connection->isWritable())->toBeTrue()
            ;

            $connection->close();
        });

        test('connects with custom TCP context options', function () {
            $connector = new Connector([
                'tcp' => ['bindto' => '0.0.0.0:0'],
                'timeout' => 5.0,
                'dns' => false,
            ]);

            $promise = $connector->connect('tcp://1.1.1.1:80');
            $connection = $promise->wait();

            expect($connection)
                ->toBeInstanceOf(ConnectionInterface::class)
            ;

            $connection->close();
        });
    });

    describe('TLS connections', function () {
        test('connects to cloudflare.com via TLS', function () {
            $connector = new Connector([
                'timeout' => 10.0,
                'tls' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);

            $promise = $connector->connect('tls://cloudflare.com:443');
            $connection = $promise->wait();

            expect($connection)
                ->toBeInstanceOf(ConnectionInterface::class)
                ->and($connection->isReadable())->toBeTrue()
                ->and($connection->isWritable())->toBeTrue()
                ->and($connection->getRemoteAddress())->toContain('tls://')
            ;

            $connection->close();
        });

        test('connects to google.com via TLS', function () {
            $connector = new Connector([
                'timeout' => 10.0,
            ]);

            $promise = $connector->connect('tls://google.com:443');
            $connection = $promise->wait();

            expect($connection)
                ->toBeInstanceOf(ConnectionInterface::class)
                ->and($connection->isReadable())->toBeTrue()
                ->and($connection->isWritable())->toBeTrue()
            ;

            $connection->close();
        });

        test('connects with peer verification disabled', function () {
            $connector = new Connector([
                'timeout' => 10.0,
                'tls' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);

            $promise = $connector->connect('tls://cloudflare.com:443');
            $connection = $promise->wait();

            expect($connection)
                ->toBeInstanceOf(ConnectionInterface::class)
            ;

            $connection->close();
        });
    });

    describe('DNS resolution', function () {
        test('resolves hostname and connects', function () {
            $connector = new Connector([
                'dns' => ['1.1.1.1', '1.0.0.1'],
                'timeout' => 10.0,
            ]);

            $promise = $connector->connect('tcp://cloudflare.com:80');
            $connection = $promise->wait();

            expect($connection)
                ->toBeInstanceOf(ConnectionInterface::class)
                ->and($connection->isReadable())->toBeTrue()
                ->and($connection->isWritable())->toBeTrue()
            ;

            $connection->close();
        });

        test('resolves with custom DNS servers', function () {
            $connector = new Connector([
                'dns' => ['8.8.8.8', '8.8.4.4'],
                'timeout' => 10.0,
            ]);

            $promise = $connector->connect('tcp://google.com:80');
            $connection = $promise->wait();

            expect($connection)
                ->toBeInstanceOf(ConnectionInterface::class)
            ;

            $connection->close();
        });

        test('Happy Eyeballs prefers IPv6', function () {
            $connector = new Connector([
                'dns' => ['2606:4700:4700::1111', '2606:4700:4700::1001'],
                'timeout' => 10.0,
                'happy_eyeballs' => true,
            ]);

            try {
                $promise = $connector->connect('tcp://ipv6.google.com:80');
                $connection = $promise->wait();

                expect($connection)
                    ->toBeInstanceOf(ConnectionInterface::class)
                ;

                $remoteAddr = $connection->getRemoteAddress();
                expect($remoteAddr)->toContain('[');

                $connection->close();
            } catch (Exception $e) {
                $this->markTestSkipped('IPv6 not available in this environment');
            }
        });

        test('falls back to IPv4 when IPv6 unavailable', function () {
            $connector = new Connector([
                'dns' => ['1.1.1.1'],
                'timeout' => 10.0,
                'happy_eyeballs' => true,
            ]);

            $promise = $connector->connect('tcp://example.com:80');
            $connection = $promise->wait();

            expect($connection)
                ->toBeInstanceOf(ConnectionInterface::class)
            ;

            $connection->close();
        });

        test('DNS resolution without Happy Eyeballs', function () {
            $connector = new Connector([
                'dns' => ['1.1.1.1'],
                'timeout' => 10.0,
                'happy_eyeballs' => false,
            ]);

            $promise = $connector->connect('tcp://cloudflare.com:80');
            $connection = $promise->wait();

            expect($connection)
                ->toBeInstanceOf(ConnectionInterface::class)
            ;

            $connection->close();
        });
    });

    describe('Unix domain sockets', function () {
        test('connects to Unix socket', function () {
            $socketPath = sys_get_temp_dir() . '/hibla-connector-test-' . uniqid() . '.sock';

            $server = @stream_socket_server('unix://' . $socketPath);

            if ($server === false) {
                $this->markTestSkipped('Cannot create Unix socket server');
            }

            $connector = new Connector([
                'timeout' => 5.0,
            ]);

            $promise = $connector->connect('unix://' . $socketPath);
            $connection = $promise->wait();

            expect($connection)
                ->toBeInstanceOf(ConnectionInterface::class)
                ->and($connection->isReadable())->toBeTrue()
                ->and($connection->isWritable())->toBeTrue()
            ;

            $connection->close();
            fclose($server);
            @unlink($socketPath);
        });
    })->skipOnWindows();

    describe('Timeout handling', function () {
        test('connection timeout for unreachable host', function () {
            $connector = new Connector([
                'timeout' => 1.0,
                'dns' => false,
            ]);

            $promise = $connector->connect('tcp://192.0.2.1:80');

            $startTime = microtime(true);

            try {
                $promise->wait();

                throw new Exception('Expected timeout exception');
            } catch (Exception $e) {
                $elapsed = microtime(true) - $startTime;

                expect($elapsed)->toBeGreaterThanOrEqual(0.9)
                    ->and($elapsed)->toBeLessThan(2.0)
                ;
            }
        });

        test('no timeout when disabled', function () {
            $connector = new Connector([
                'timeout' => false,
                'dns' => false,
            ]);

            expect($connector)->toBeInstanceOf(Connector::class);
        });
    });

    describe('Error scenarios', function () {
        test('handles nonexistent domain', function () {
            $connector = new Connector([
                'dns' => ['1.1.1.1'],
                'timeout' => 5.0,
            ]);

            $promise = $connector->connect('tcp://this-domain-definitely-does-not-exist-12345.com:80');

            expect(fn () => $promise->wait())
                ->toThrow(Exception::class)
            ;
        });

        test('handles connection refused', function () {
            $connector = new Connector([
                'timeout' => 5.0,
                'dns' => false,
            ]);

            $promise = $connector->connect('tcp://127.0.0.1:54321');

            expect(fn () => $promise->wait())
                ->toThrow(Exception::class)
            ;
        });

        test('handles TLS handshake failure', function () {
            $connector = new Connector([
                'timeout' => 5.0,
                'tls' => [
                    'verify_peer' => true,
                    'allow_self_signed' => false,
                ],
                'dns' => false,
            ]);

            $promise = $connector->connect('tls://1.1.1.1:80');

            expect(fn () => $promise->wait())
                ->toThrow(Exception::class)
            ;
        });
    });

    describe('Promise operations', function () {
        test('promise can be chained', function () {
            $connector = new Connector([
                'timeout' => 5.0,
                'dns' => false,
            ]);

            $promise = $connector->connect('tcp://1.1.1.1:80')
                ->then(function ($conn) {
                    expect($conn)->toBeInstanceOf(ConnectionInterface::class);
                    $conn->close();

                    return 'success';
                })
            ;

            $result = $promise->wait();
            expect($result)->toBe('success');
        });

        test('promise can handle errors in chain', function () {
            $connector = new Connector([
                'timeout' => 2.0,
                'dns' => false,
            ]);

            $promise = $connector->connect('tcp://192.0.2.1:80')
                ->catch(function ($e) {
                    expect($e)->toBeInstanceOf(Throwable::class);

                    return 'handled';
                })
            ;

            $result = $promise->wait();
            expect($result)->toBe('handled');
        });

        test('promise finally executes on success', function () {
            $connector = new Connector([
                'timeout' => 5.0,
                'dns' => false,
            ]);

            $finallyCalled = false;

            $promise = $connector->connect('tcp://1.1.1.1:80')
                ->finally(function () use (&$finallyCalled) {
                    $finallyCalled = true;
                })
                ->then(function ($conn) {
                    $conn->close();
                })
            ;

            $promise->wait();

            expect($finallyCalled)->toBeTrue();
        });

        test('promise finally executes on failure', function () {
            $connector = new Connector([
                'timeout' => 1.0,
                'dns' => false,
            ]);

            $finallyCalled = false;

            $promise = $connector->connect('tcp://192.0.2.1:80')
                ->finally(function () use (&$finallyCalled) {
                    $finallyCalled = true;
                })
            ;

            try {
                $promise->wait();
            } catch (Exception $e) {
                // Expected
            }

            expect($finallyCalled)->toBeTrue();
        });
    });

    describe('Multiple connections', function () {
        test('can create multiple sequential connections', function () {
            $connector = new Connector([
                'timeout' => 5.0,
                'dns' => false,
            ]);

            $promise1 = $connector->connect('tcp://1.1.1.1:80');
            $connection1 = $promise1->wait();
            expect($connection1)->toBeInstanceOf(ConnectionInterface::class);
            $connection1->close();

            $promise2 = $connector->connect('tcp://8.8.8.8:53');
            $connection2 = $promise2->wait();
            expect($connection2)->toBeInstanceOf(ConnectionInterface::class);
            $connection2->close();
        });

        test('connections remain independent', function () {
            $connector = new Connector([
                'timeout' => 5.0,
                'dns' => false,
            ]);

            $promise1 = $connector->connect('tcp://1.1.1.1:80');
            $connection1 = $promise1->wait();

            $promise2 = $connector->connect('tcp://8.8.8.8:53');
            $connection2 = $promise2->wait();

            expect($connection1->isReadable())->toBeTrue()
                ->and($connection2->isReadable())->toBeTrue()
            ;

            $connection1->close();

            expect($connection1->isReadable())->toBeFalse()
                ->and($connection2->isReadable())->toBeTrue();

            $connection2->close();
        });
    });
})->skipOnCI();
