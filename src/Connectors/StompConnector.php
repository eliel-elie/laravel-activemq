<?php

namespace Elielelie\ActiveMQ\Connectors;

use Elielelie\ActiveMQ\Queue\ActiveMQQueue;
use Elielelie\ActiveMQ\Queue\Config;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\Events\WorkerStopping;
use Stomp\Exception\ConnectionException;
use Stomp\Exception\StompException;

class StompConnector implements ConnectorInterface
{
    private Dispatcher $dispatcher;

    public function __construct(Dispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * Establish a queue connection.
     *
     * @return Queue
     *
     * @throws ConnectionException
     * @throws StompException
     */
    public function connect(array $config)
    {
        /** @var ActiveMQQueue $queue */
        $queue = app(ActiveMQQueue::class);

        // If using Horizon and there's specific queue configuration
        if ($this->isHorizonContext() && isset($config['queue'])) {
            $horizonQueues = is_array($config['queue'])
                ? $config['queue']
                : explode(',', $config['queue']);

            $queue->setWorkerQueues($horizonQueues);
        }

        $this->dispatcher->listen(WorkerStopping::class, static function () use ($queue): void {
            $queue->disconnect();
        });

        return $queue;
    }

    /**
     * Check if we're running in a Horizon context.
     */
    protected function isHorizonContext(): bool
    {
        if (! Config::get('horizon.enabled')) {
            return false;
        }

        if (! app()->runningInConsole()) {
            return false;
        }

        // Check if horizon:work command is running
        $argv = $_SERVER['argv'] ?? [];

        foreach ($argv as $arg) {
            if (str_contains($arg, 'horizon') || str_contains($arg, 'queue:work')) {
                return true;
            }
        }

        return false;
    }
}
