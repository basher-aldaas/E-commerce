<?php

namespace Tests\Feature\Warehouse;

use App\Services\Warehouse\WarehouseStub;
use Grpc\UnaryCall;
use RuntimeException;

/**
 * Replays scripted gRPC replies, without a network, and records each call.
 */
class ScriptedWarehouseStub extends WarehouseStub
{
    /** @var list<array{method: string, request: mixed, options: array}> */
    public array $calls = [];

    /** @param list<array{0: mixed, 1: int, 2?: string}> $replies [response, grpc code, details] */
    public function __construct(private array $replies)
    {
        // The real constructor opens a channel, which these tests don't need.
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

    public function GetStock($request, array $metadata = [], array $options = []): UnaryCall
    {
        return $this->reply('GetStock', $request, $options);
    }

    public function Reserve($request, array $metadata = [], array $options = []): UnaryCall
    {
        return $this->reply('Reserve', $request, $options);
    }

    public function Confirm($request, array $metadata = [], array $options = []): UnaryCall
    {
        return $this->reply('Confirm', $request, $options);
    }

    public function Release($request, array $metadata = [], array $options = []): UnaryCall
    {
        return $this->reply('Release', $request, $options);
    }

    public function ReturnStock($request, array $metadata = [], array $options = []): UnaryCall
    {
        return $this->reply('ReturnStock', $request, $options);
    }

    public function InitializeStock($request, array $metadata = [], array $options = []): UnaryCall
    {
        return $this->reply('InitializeStock', $request, $options);
    }

    public function SetStock($request, array $metadata = [], array $options = []): UnaryCall
    {
        return $this->reply('SetStock', $request, $options);
    }
}
