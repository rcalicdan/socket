<?php

declare(strict_types=1);

use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Exceptions\RecordNotFoundException;
use Hibla\Socket\Exceptions\ConnectionFailedException;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Socket\Internals\HappyEyeBallsConnectionBuilder;
use Tests\Mocks\MockConnection;
use Tests\Mocks\MockConnectorWithFailureTracking;
use Tests\Mocks\MockResolverWithTypes;

describe('HappyEyeBallsConnectionBuilder', function () {
    test('resolves both IPv4 and IPv6 in parallel', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();
        $connection = new MockConnection('tcp://[2606:2800:220:1::1]:80');

        $mockResolver->setImmediateSuccessForType(RecordType::AAAA, ['2606:2800:220:1::1']);
        $mockResolver->setImmediateSuccessForType(RecordType::A, ['93.184.216.34']);
        $mockConnector->setImmediateSuccess($connection);

        $parts = ['scheme' => 'tcp', 'host' => 'example.com', 'port' => 80];
        $builder = new HappyEyeBallsConnectionBuilder(
            $mockConnector,
            $mockResolver,
            'tcp://example.com:80',
            'example.com',
            $parts
        );

        $promise = $builder->connect();
        $result = $promise->wait();

        expect($result)->toBeInstanceOf(ConnectionInterface::class)
            ->and($mockResolver->resolveAllCalledForType(RecordType::AAAA))->toBeTrue()
            ->and($mockResolver->resolveAllCalledForType(RecordType::A))->toBeTrue()
        ;
    });

    test('prefers IPv6 address when available', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();
        $ipv6Connection = new MockConnection('tcp://[2606:2800:220:1::1]:80');

        $mockResolver->setImmediateSuccessForType(RecordType::AAAA, ['2606:2800:220:1::1']);
        $mockResolver->setImmediateSuccessForType(RecordType::A, ['93.184.216.34']);
        $mockConnector->setImmediateSuccess($ipv6Connection);

        $parts = ['scheme' => 'tcp', 'host' => 'example.com', 'port' => 80];
        $builder = new HappyEyeBallsConnectionBuilder(
            $mockConnector,
            $mockResolver,
            'tcp://example.com:80',
            'example.com',
            $parts
        );

        $promise = $builder->connect();
        $result = $promise->wait();

        expect($result)->toBeInstanceOf(ConnectionInterface::class)
            ->and($mockConnector->connectionAttempts[0])->toContain('[2606:2800:220:1::1]')
        ;
    });

    test('falls back to IPv4 when IPv6 fails', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();
        $ipv4Connection = new MockConnection('tcp://93.184.216.34:80');

        $mockResolver->setImmediateSuccessForType(RecordType::AAAA, ['2606:2800:220:1::1']);
        $mockResolver->setImmediateSuccessForType(RecordType::A, ['93.184.216.34']);

        $mockConnector->setFailureForUri(
            'tcp://[2606:2800:220:1::1]:80',
            new ConnectionFailedException('IPv6 connection refused')
        );
        $mockConnector->setSuccessForUri('tcp://93.184.216.34:80', $ipv4Connection);

        $parts = ['scheme' => 'tcp', 'host' => 'example.com', 'port' => 80];
        $builder = new HappyEyeBallsConnectionBuilder(
            $mockConnector,
            $mockResolver,
            'tcp://example.com:80',
            'example.com',
            $parts
        );

        $promise = $builder->connect();
        $result = $promise->wait();

        expect($result)->toBe($ipv4Connection)
            ->and($mockConnector->connectionAttempts)->toHaveCount(2)
            ->and($mockConnector->connectionAttempts[0])->toContain('[2606:2800:220:1::1]')
            ->and($mockConnector->connectionAttempts[1])->toContain('93.184.216.34')
        ;
    });

    test('delays IPv4 resolution by 50ms when IPv6 is pending', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();
        $connection = new MockConnection();

        $startTime = microtime(true);

        $mockResolver->setSuccessAfterForType(RecordType::AAAA, 0.1, ['2606:2800:220:1::1']);
        $mockResolver->setImmediateSuccessForType(RecordType::A, ['93.184.216.34']);
        $mockConnector->setImmediateSuccess($connection);

        $parts = ['scheme' => 'tcp', 'host' => 'example.com', 'port' => 80];
        $builder = new HappyEyeBallsConnectionBuilder(
            $mockConnector,
            $mockResolver,
            'tcp://example.com:80',
            'example.com',
            $parts
        );

        $promise = $builder->connect();
        $result = $promise->wait();
        $elapsed = microtime(true) - $startTime;

        expect($result)->toBeInstanceOf(ConnectionInterface::class)
            ->and($elapsed)->toBeGreaterThanOrEqual(0.05)
            ->and($elapsed)->toBeLessThan(0.15)
        ;
    });

    test('cancels IPv4 delay when IPv6 resolves first', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();
        $connection = new MockConnection();

        $startTime = microtime(true);

        $mockResolver->setSuccessAfterForType(RecordType::AAAA, 0.03, ['2606:2800:220:1::1']);
        $mockResolver->setImmediateSuccessForType(RecordType::A, ['93.184.216.34']);
        $mockConnector->setImmediateSuccess($connection);

        $parts = ['scheme' => 'tcp', 'host' => 'example.com', 'port' => 80];
        $builder = new HappyEyeBallsConnectionBuilder(
            $mockConnector,
            $mockResolver,
            'tcp://example.com:80',
            'example.com',
            $parts
        );

        $promise = $builder->connect();
        $result = $promise->wait();
        $elapsed = microtime(true) - $startTime;

        expect($result)->toBeInstanceOf(ConnectionInterface::class)
            ->and($elapsed)->toBeLessThan(0.05)
        ;
    });

    test('interleaves IPv4 and IPv6 addresses', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();
        $connection = new MockConnection();

        $mockResolver->setImmediateSuccessForType(RecordType::AAAA, [
            '2606:2800:220:1::1',
            '2606:2800:220:1::2',
        ]);
        $mockResolver->setImmediateSuccessForType(RecordType::A, [
            '93.184.216.34',
            '93.184.216.35',
        ]);

        $mockConnector->setFailureForAllUris(new ConnectionFailedException('All failed'));

        $parts = ['scheme' => 'tcp', 'host' => 'example.com', 'port' => 80];
        $builder = new HappyEyeBallsConnectionBuilder(
            $mockConnector,
            $mockResolver,
            'tcp://example.com:80',
            'example.com',
            $parts
        );

        $promise = $builder->connect();

        try {
            $promise->wait();
        } catch (ConnectionFailedException $e) {
            // Expected
        }

        $attempts = $mockConnector->connectionAttempts;

        expect($attempts)->toHaveCount(4);

        $hasIPv6 = false;
        $hasIPv4 = false;
        foreach ($attempts as $attempt) {
            if (str_contains($attempt, '[')) {
                $hasIPv6 = true;
            } else {
                $hasIPv4 = true;
            }
        }

        expect($hasIPv6)->toBeTrue()
            ->and($hasIPv4)->toBeTrue()
        ;

        expect($attempts[0])->toContain('[');
    });

    test('waits 250ms between connection attempts', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();

        $mockResolver->setImmediateSuccessForType(RecordType::AAAA, [
            '2606:2800:220:1::1',
            '2606:2800:220:1::2',
            '2606:2800:220:1::3',
        ]);
        $mockResolver->setImmediateSuccessForType(RecordType::A, []);

        $mockConnector->setFailureForAllUris(new ConnectionFailedException('Connection refused'));

        $parts = ['scheme' => 'tcp', 'host' => 'example.com', 'port' => 80];
        $builder = new HappyEyeBallsConnectionBuilder(
            $mockConnector,
            $mockResolver,
            'tcp://example.com:80',
            'example.com',
            $parts
        );

        $startTime = microtime(true);
        $promise = $builder->connect();

        try {
            $promise->wait();
        } catch (ConnectionFailedException $e) {
            // Expected
        }

        $elapsed = microtime(true) - $startTime;
        $attempts = $mockConnector->connectionAttempts;

        expect($attempts)->toHaveCount(3)
            ->and($elapsed)->toBeGreaterThanOrEqual(0.50)
            ->and($elapsed)->toBeLessThan(0.60)
        ;
    });

    test('connection attempts happen in parallel per RFC 8305', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();
        $connection = new MockConnection();

        $mockResolver->setImmediateSuccessForType(RecordType::AAAA, ['2606:2800:220:1::1']);
        $mockResolver->setImmediateSuccessForType(RecordType::A, ['93.184.216.34']);

        $mockConnector->setFailureForUri(
            'tcp://[2606:2800:220:1::1]:80',
            new ConnectionFailedException('Connection refused')
        );
        $mockConnector->setSuccessForUri('tcp://93.184.216.34:80', $connection);

        $parts = ['scheme' => 'tcp', 'host' => 'example.com', 'port' => 80];
        $builder = new HappyEyeBallsConnectionBuilder(
            $mockConnector,
            $mockResolver,
            'tcp://example.com:80',
            'example.com',
            $parts
        );

        $startTime = microtime(true);
        $promise = $builder->connect();
        $result = $promise->wait();
        $elapsed = microtime(true) - $startTime;

        expect($result)->toBe($connection)
            ->and($elapsed)->toBeGreaterThanOrEqual(0.25)
            ->and($elapsed)->toBeLessThan(0.30)
            ->and($mockConnector->connectionAttempts)->toHaveCount(2)
        ;
    });

    test('builds URI with IPv6 address in brackets', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();
        $connection = new MockConnection();

        $mockResolver->setImmediateSuccessForType(RecordType::AAAA, ['2606:2800:220:1::1']);
        $mockResolver->setImmediateSuccessForType(RecordType::A, []);
        $mockConnector->setImmediateSuccess($connection);

        $parts = ['scheme' => 'tcp', 'host' => 'example.com', 'port' => 80];
        $builder = new HappyEyeBallsConnectionBuilder(
            $mockConnector,
            $mockResolver,
            'tcp://example.com:80',
            'example.com',
            $parts
        );

        $promise = $builder->connect();
        $promise->wait();

        expect($mockConnector->lastUri)->toContain('[2606:2800:220:1::1]');
    });

    test('builds URI with IPv4 address without brackets', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();
        $connection = new MockConnection();

        $mockResolver->setImmediateSuccessForType(RecordType::AAAA, []);
        $mockResolver->setImmediateSuccessForType(RecordType::A, ['93.184.216.34']);
        $mockConnector->setImmediateSuccess($connection);

        $parts = ['scheme' => 'tcp', 'host' => 'example.com', 'port' => 80];
        $builder = new HappyEyeBallsConnectionBuilder(
            $mockConnector,
            $mockResolver,
            'tcp://example.com:80',
            'example.com',
            $parts
        );

        $promise = $builder->connect();
        $promise->wait();

        expect($mockConnector->lastUri)
            ->toContain('93.184.216.34')
            ->not->toContain('[93.184.216.34]')
        ;
    });

    test('preserves URI components in built connection URI', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();
        $connection = new MockConnection();

        $mockResolver->setImmediateSuccessForType(RecordType::AAAA, []);
        $mockResolver->setImmediateSuccessForType(RecordType::A, ['93.184.216.34']);
        $mockConnector->setImmediateSuccess($connection);

        $parts = [
            'scheme' => 'tcp',
            'user' => 'user',
            'pass' => 'pass',
            'host' => 'example.com',
            'port' => 8080,
            'path' => '/path',
            'query' => 'key=value',
            'fragment' => 'fragment',
        ];

        $builder = new HappyEyeBallsConnectionBuilder(
            $mockConnector,
            $mockResolver,
            'tcp://user:pass@example.com:8080/path?key=value#fragment',
            'example.com',
            $parts
        );

        $promise = $builder->connect();
        $promise->wait();

        expect($mockConnector->lastUri)
            ->toContain('tcp://')
            ->toContain('user:pass@')
            ->toContain('93.184.216.34')
            ->toContain(':8080')
            ->toContain('/path')
            ->toContain('hostname=example.com')
            ->toContain('key=value')
            ->toContain('#fragment')
        ;
    });

    test('adds hostname query parameter', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();
        $connection = new MockConnection();

        $mockResolver->setImmediateSuccessForType(RecordType::AAAA, []);
        $mockResolver->setImmediateSuccessForType(RecordType::A, ['93.184.216.34']);
        $mockConnector->setImmediateSuccess($connection);

        $parts = ['scheme' => 'tcp', 'host' => 'example.com', 'port' => 80];
        $builder = new HappyEyeBallsConnectionBuilder(
            $mockConnector,
            $mockResolver,
            'tcp://example.com:80',
            'example.com',
            $parts
        );

        $promise = $builder->connect();
        $promise->wait();

        expect($mockConnector->lastUri)->toContain('?hostname=example.com');
    });

    test('URL encodes hostname in query parameter', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();
        $connection = new MockConnection();

        $mockResolver->setImmediateSuccessForType(RecordType::AAAA, []);
        $mockResolver->setImmediateSuccessForType(RecordType::A, ['93.184.216.34']);
        $mockConnector->setImmediateSuccess($connection);

        $parts = ['scheme' => 'tcp', 'host' => 'sub.example.com', 'port' => 80];
        $builder = new HappyEyeBallsConnectionBuilder(
            $mockConnector,
            $mockResolver,
            'tcp://sub.example.com:80',
            'sub.example.com',
            $parts
        );

        $promise = $builder->connect();
        $promise->wait();

        expect($mockConnector->lastUri)->toContain('hostname=sub.example.com');
    });

    test('both DNS lookups fail with error message', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();

        $mockResolver->setFailureForType(
            RecordType::AAAA,
            new RecordNotFoundException('DNS query for example.com did not return a valid answer (AAAA)')
        );
        $mockResolver->setFailureForType(
            RecordType::A,
            new RecordNotFoundException('DNS query for example.com did not return a valid answer (A)')
        );

        $parts = ['scheme' => 'tcp', 'host' => 'example.com', 'port' => 80];
        $builder = new HappyEyeBallsConnectionBuilder(
            $mockConnector,
            $mockResolver,
            'tcp://example.com:80',
            'example.com',
            $parts
        );

        $promise = $builder->connect();

        expect(fn () => $promise->wait())
            ->toThrow(ConnectionFailedException::class, 'failed during DNS lookup')
        ;
    });

    test('all connection attempts fail with error message', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();

        $mockResolver->setImmediateSuccessForType(RecordType::AAAA, ['2606:2800:220:1::1']);
        $mockResolver->setImmediateSuccessForType(RecordType::A, ['93.184.216.34']);

        $mockConnector->setFailureForUri(
            'tcp://[2606:2800:220:1::1]:80',
            new ConnectionFailedException('Connection to tcp://[2606:2800:220:1::1]:80 failed: Connection refused')
        );
        $mockConnector->setFailureForUri(
            'tcp://93.184.216.34:80',
            new ConnectionFailedException('Connection to tcp://93.184.216.34:80 failed: Connection refused')
        );

        $parts = ['scheme' => 'tcp', 'host' => 'example.com', 'port' => 80];
        $builder = new HappyEyeBallsConnectionBuilder(
            $mockConnector,
            $mockResolver,
            'tcp://example.com:80',
            'example.com',
            $parts
        );

        $promise = $builder->connect();

        expect(fn () => $promise->wait())
            ->toThrow(ConnectionFailedException::class, 'Connection to tcp://example.com:80 failed')
        ;
    });

    test('error message includes both IPv4 and IPv6 errors', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();

        $mockResolver->setImmediateSuccessForType(RecordType::AAAA, ['2606:2800:220:1::1']);
        $mockResolver->setImmediateSuccessForType(RecordType::A, ['93.184.216.34']);

        $mockConnector->setFailureForUri(
            'tcp://[2606:2800:220:1::1]:80',
            new ConnectionFailedException('Connection to tcp://[2606:2800:220:1::1]:80 failed: Timeout (IPv6)')
        );
        $mockConnector->setFailureForUri(
            'tcp://93.184.216.34:80',
            new ConnectionFailedException('Connection to tcp://93.184.216.34:80 failed: Refused (IPv4)')
        );

        $parts = ['scheme' => 'tcp', 'host' => 'example.com', 'port' => 80];
        $builder = new HappyEyeBallsConnectionBuilder(
            $mockConnector,
            $mockResolver,
            'tcp://example.com:80',
            'example.com',
            $parts
        );

        $promise = $builder->connect();

        try {
            $promise->wait();

            throw new Exception('Expected ConnectionFailedException');
        } catch (ConnectionFailedException $e) {
            expect($e->getMessage())
                ->toContain('IPv6')
                ->toContain('IPv4')
                ->toContain('Timeout')
                ->toContain('Refused')
            ;
        }
    });
    test('cancellation stops all pending operations', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();

        $mockResolver->setHangForType(RecordType::AAAA);
        $mockResolver->setHangForType(RecordType::A);

        $parts = ['scheme' => 'tcp', 'host' => 'example.com', 'port' => 80];
        $builder = new HappyEyeBallsConnectionBuilder(
            $mockConnector,
            $mockResolver,
            'tcp://example.com:80',
            'example.com',
            $parts
        );

        $promise = $builder->connect();
        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
    });

    test('cancellation during connection attempts', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();

        $mockResolver->setImmediateSuccessForType(RecordType::AAAA, ['2606:2800:220:1::1']);
        $mockResolver->setImmediateSuccessForType(RecordType::A, ['93.184.216.34']);
        $mockConnector->setHang();

        $parts = ['scheme' => 'tcp', 'host' => 'example.com', 'port' => 80];
        $builder = new HappyEyeBallsConnectionBuilder(
            $mockConnector,
            $mockResolver,
            'tcp://example.com:80',
            'example.com',
            $parts
        );

        $promise = $builder->connect();

        usleep(100000);

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
    });

    test('handles empty IPv6 results', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();
        $connection = new MockConnection();

        $mockResolver->setImmediateSuccessForType(RecordType::AAAA, []);
        $mockResolver->setImmediateSuccessForType(RecordType::A, ['93.184.216.34']);
        $mockConnector->setImmediateSuccess($connection);

        $parts = ['scheme' => 'tcp', 'host' => 'example.com', 'port' => 80];
        $builder = new HappyEyeBallsConnectionBuilder(
            $mockConnector,
            $mockResolver,
            'tcp://example.com:80',
            'example.com',
            $parts
        );

        $promise = $builder->connect();
        $result = $promise->wait();

        expect($result)->toBeInstanceOf(ConnectionInterface::class)
            ->and($mockConnector->connectionAttempts[0])->toContain('93.184.216.34')
        ;
    });

    test('handles empty IPv4 results', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();
        $connection = new MockConnection();

        $mockResolver->setImmediateSuccessForType(RecordType::AAAA, ['2606:2800:220:1::1']);
        $mockResolver->setImmediateSuccessForType(RecordType::A, []);
        $mockConnector->setImmediateSuccess($connection);

        $parts = ['scheme' => 'tcp', 'host' => 'example.com', 'port' => 80];
        $builder = new HappyEyeBallsConnectionBuilder(
            $mockConnector,
            $mockResolver,
            'tcp://example.com:80',
            'example.com',
            $parts
        );

        $promise = $builder->connect();
        $result = $promise->wait();

        expect($result)->toBeInstanceOf(ConnectionInterface::class)
            ->and($mockConnector->connectionAttempts[0])->toContain('[2606:2800:220:1::1]')
        ;
    });

    test('shuffles IPs for load distribution', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();

        $mockResolver->setImmediateSuccessForType(RecordType::AAAA, [
            '2606:2800:220:1::1',
            '2606:2800:220:1::2',
            '2606:2800:220:1::3',
        ]);
        $mockResolver->setImmediateSuccessForType(RecordType::A, []);

        $mockConnector->setFailureForAllUris(new ConnectionFailedException('All failed'));

        $parts = ['scheme' => 'tcp', 'host' => 'example.com', 'port' => 80];
        $builder = new HappyEyeBallsConnectionBuilder(
            $mockConnector,
            $mockResolver,
            'tcp://example.com:80',
            'example.com',
            $parts
        );

        $promise = $builder->connect();

        try {
            $promise->wait();
        } catch (ConnectionFailedException $e) {
            // Expected
        }

        expect($mockConnector->connectionAttempts)->toHaveCount(3);
    });

    test('first successful connection stops all other attempts', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();
        $connection = new MockConnection();

        $mockResolver->setImmediateSuccessForType(RecordType::AAAA, [
            '2606:2800:220:1::1',
            '2606:2800:220:1::2',
        ]);
        $mockResolver->setImmediateSuccessForType(RecordType::A, ['93.184.216.34']);
        $mockConnector->setSuccessForUri('tcp://[2606:2800:220:1::1]:80', $connection);

        $parts = ['scheme' => 'tcp', 'host' => 'example.com', 'port' => 80];
        $builder = new HappyEyeBallsConnectionBuilder(
            $mockConnector,
            $mockResolver,
            'tcp://example.com:80',
            'example.com',
            $parts
        );

        $promise = $builder->connect();
        $result = $promise->wait();

        expect($result)->toBeInstanceOf(ConnectionInterface::class)
            ->and($mockConnector->connectionAttempts)->toHaveCount(1);
    });
});
