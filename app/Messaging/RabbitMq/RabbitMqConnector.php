<?php

namespace App\Messaging\RabbitMq;

use PhpAmqpLib\Connection\AMQPStreamConnection;

/** Opens AMQP connections from config('messaging.rabbitmq'). */
class RabbitMqConnector
{
    public function connect(): AMQPStreamConnection
    {
        $config = config('messaging.rabbitmq');

        return new AMQPStreamConnection(
            host: $config['host'],
            port: $config['port'],
            user: $config['user'],
            password: $config['password'],
            vhost: $config['vhost'],
            connection_timeout: $config['connection_timeout'],
            read_write_timeout: $config['read_write_timeout'],
            keepalive: true,
            heartbeat: $config['heartbeat'],
        );
    }
}
