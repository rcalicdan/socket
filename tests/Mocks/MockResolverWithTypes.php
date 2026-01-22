<?php

declare(strict_types=1);

namespace Tests\Mocks;

use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Interfaces\ResolverInterface;
use Hibla\EventLoop\Loop;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

class MockResolverWithTypes implements ResolverInterface
{
    private array $ipsByType = [];
    private array $errorsByType = [];
    private array $delaysByType = [];
    private array $hangByType = [];
    private array $resolveAllCalls = [];

    public function setImmediateSuccessForType(RecordType $type, array $ips): self
    {
        $this->ipsByType[$type->value] = $ips;
        $this->delaysByType[$type->value] = null;
        $this->errorsByType[$type->value] = null;
        $this->hangByType[$type->value] = false;

        return $this;
    }

    public function setSuccessAfterForType(RecordType $type, float $delay, array $ips): self
    {
        $this->ipsByType[$type->value] = $ips;
        $this->delaysByType[$type->value] = $delay;
        $this->errorsByType[$type->value] = null;
        $this->hangByType[$type->value] = false;

        return $this;
    }

    public function setFailureForType(RecordType $type, \Throwable $error): self
    {
        $this->errorsByType[$type->value] = $error;
        $this->delaysByType[$type->value] = null;
        $this->ipsByType[$type->value] = null;
        $this->hangByType[$type->value] = false;

        return $this;
    }

    public function setHangForType(RecordType $type): self
    {
        $this->hangByType[$type->value] = true;
        $this->delaysByType[$type->value] = null;

        return $this;
    }

    public function resolveAllCalledForType(RecordType $type): bool
    {
        return in_array($type->value, $this->resolveAllCalls, true);
    }

    public function resolve(string $domain): PromiseInterface
    {
        return $this->resolveAll($domain, RecordType::A)
            ->then(fn ($ips) => $ips[0] ?? null)
        ;
    }

    public function resolveAll(string $domain, RecordType $type = RecordType::A): PromiseInterface
    {
        $this->resolveAllCalls[] = $type->value;

        if ($this->hangByType[$type->value] ?? false) {
            return new Promise();
        }

        $delay = $this->delaysByType[$type->value] ?? null;
        $ips = $this->ipsByType[$type->value] ?? null;
        $error = $this->errorsByType[$type->value] ?? null;

        if ($delay === null) {
            if ($ips !== null) {
                return Promise::resolved($ips);
            }
            if ($error !== null) {
                return Promise::rejected($error);
            }

            return Promise::resolved([]);
        }

        /** @var Promise $promise */
        $promise = new Promise();

        Loop::addTimer($delay, function () use ($promise, $ips, $error): void {
            if ($promise->isCancelled()) {
                return;
            }

            if ($ips !== null) {
                $promise->resolve($ips);
            } elseif ($error !== null) {
                $promise->reject($error);
            } else {
                $promise->resolve([]);
            }
        });

        return $promise;
    }

    public function reset(): void
    {
        $this->ipsByType = [];
        $this->errorsByType = [];
        $this->delaysByType = [];
        $this->hangByType = [];
        $this->resolveAllCalls = [];
    }
}
