<?php

namespace Usoft\RabbitRpc\Services;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;


class ProducerRpc
{
    private $connection;
    private $channel;
    private $callback_queue;
    private $response;
    private $corr_id;
    private $withoutWaiting;
    private $queueName;

    public function __construct(string $queueName = 'default')
    {
        $this->setQueueName($queueName);
        $this->withoutWaiting = false;

        $this->connection = new AMQPStreamConnection(env('RABBITMQ_HOST'), env('RABBITMQ_PORT'), env('RABBITMQ_USER'), env('RABBITMQ_PASSWORD'));
        $this->channel = $this->connection->channel();
        list($this->callback_queue, ,) = $this->channel->queue_declare(
            "",
            false,
            true,
            true,
            false
        );
        $this->channel->basic_consume(
            $this->callback_queue,
            '',
            false,
            true,
            false,
            false,
            [
                $this,
                'onResponse'
            ]
        );
    }


    public function setQueueName(string $queueName)
    {
        $this->queueName = $queueName;
        return $this;
    }

    /**
     * Producer will won't wait for response after execute this method
     *
     * @return $this
     */
    public function withoutWaiting()
    {
        $this->withoutWaiting = true;
        return $this;
    }

    public function onResponse($rep)
    {
        if ($rep->get('correlation_id') == $this->corr_id) {
            $this->response = $rep->body;
        }
    }

    public function call(string $message)
    {
        $this->response = null;
        $this->corr_id = uniqid();

        $msg = new AMQPMessage(
            (string) $message,
            [
                'correlation_id' => $this->corr_id,
                'reply_to' => $this->callback_queue,
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            ]
        );
        $this->channel->basic_publish($msg, '', $this->queueName);

        if (!$this->withoutWaiting) {
            while (!$this->response) {
                $this->channel->wait();
            }
        }

        return $this->response;
    }
}