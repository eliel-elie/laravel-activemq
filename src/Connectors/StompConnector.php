<?php

namespace Elielelie\ActiveMQ\Connectors;

use Elielelie\ActiveMQ\Queue\ActiveMQQueue;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\Events\WorkerStopping;
use Stomp\Exception\ConnectionException;
use Stomp\Exception\StompException;

class StompConnector implements ConnectorInterface
{
    /**
     * @var Dispatcher
     */
    private Dispatcher $dispatcher;

    public function __construct(Dispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * Establish a queue connection.
     *
     * @param array $config
     * @return Queue
     *
     * @throws ConnectionException
     * @throws StompException
     */
    public function connect(array $config)
    {
        /** @var ActiveMQQueue $queue */
        $queue = app(ActiveMQQueue::class);

        $this->dispatcher->listen(WorkerStopping::class, static function () use ($queue): void {
            $queue->disconnect();
        });

        return $queue;
    }
}