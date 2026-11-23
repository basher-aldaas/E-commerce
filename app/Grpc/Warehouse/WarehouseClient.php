<?php
// GENERATED CODE -- DO NOT EDIT!

namespace App\Grpc\Warehouse;

/**
 * The Warehouse owns all stock. Callers can read it, reserve it and release a
 * reservation; nothing else. Initial stock and manual corrections are done with
 * the service's own CLI (python -m app.cli set-stock), never over gRPC.
 */
class WarehouseClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * @param \App\Grpc\Warehouse\StockRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function GetStock(\App\Grpc\Warehouse\StockRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/Warehouse/GetStock',
        $argument,
        ['\App\Grpc\Warehouse\StockResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Takes every item or none, in one transaction. Repeating a key never
     * reserves twice.
     * @param \App\Grpc\Warehouse\ReserveRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function Reserve(\App\Grpc\Warehouse\ReserveRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/Warehouse/Reserve',
        $argument,
        ['\App\Grpc\Warehouse\ReserveResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Gives a reservation's stock back: compensation when the order could not be
     * saved, or a cancelled order. Repeating it never returns stock twice.
     * @param \App\Grpc\Warehouse\ReleaseRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function Release(\App\Grpc\Warehouse\ReleaseRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/Warehouse/Release',
        $argument,
        ['\App\Grpc\Warehouse\ReleaseResponse', 'decode'],
        $metadata, $options);
    }

}
