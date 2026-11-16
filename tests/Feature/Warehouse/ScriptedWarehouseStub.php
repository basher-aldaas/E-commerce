<?php

namespace Tests\Feature\Warehouse;

use App\Grpc\Warehouse\ReleaseRequest;
use App\Grpc\Warehouse\ReserveRequest;
use App\Grpc\Warehouse\StockRequest;
use App\Grpc\Warehouse\WarehouseClient;
use Grpc\UnaryCall;
use RuntimeException;

/**
 * Replays scripted gRPC replies through the generated client's interface, without
 * a network, and records each call.
 */
class ScriptedWarehouseStub extends WarehouseClient
{
    /** @var list<array{method: string, request: mixed, options: array}> */
    public array $calls = [];

    /** Whether waitForReady() finds a connection. */
    public bool $ready = true;

    /** @var list<int> Microseconds passed to each waitForReady() call. */
    public array $readyWaits = [];

    /** @param list<array{0: mixed, 1: int, 2?: string}> $replies [response, grpc code, details] */
    public function __construct(private array $replies)
    {
        // The real constructor opens a channel, which these tests don't need.
    }

    public function waitForReady($timeout)
    {
        $this->readyWaits[] = $timeout;

        return $this->ready;
    }

    private function reply(string $method, $request, array $options): UnaryCall
    {
        $this->calls[] = compact('method', 'request', 'options');

        $reply = array_shift($this->replies)
            ?? throw new RuntimeException("Unexpected extra call to {$method}");

        return new class($reply) extends UnaryCall
        {
            public function __construct(private readonly array $reply)
            {
                // Replaces the real call, which needs a channel.
            }

            public function wait(): array
            {
                return [$this->reply[0], (object) [
                    'code' => $this->reply[1],
                    'details' => $this->reply[2] ?? '',
                    'metadata' => [],
                ]];
            }
        };
    }

    public function GetStock(StockRequest $argument, $metadata = [], $options = [])
    {
        return $this->reply('GetStock', $argument, $options);
    }

    public function Reserve(ReserveRequest $argument, $metadata = [], $options = [])
    {
        return $this->reply('Reserve', $argument, $options);
    }

    public function Release(ReleaseRequest $argument, $metadata = [], $options = [])
    {
        return $this->reply('Release', $argument, $options);
    }
}
