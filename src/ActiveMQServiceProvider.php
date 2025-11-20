<?php

namespace Elielelie\ActiveMQ;

use Elielelie\ActiveMQ\Commands\TestActiveMQConnection;
use Elielelie\ActiveMQ\Connectors\StompConnector;
use Elielelie\ActiveMQ\Queue\ActiveMQQueue;
use Elielelie\ActiveMQ\Queue\ClientWrapper;
use Elielelie\ActiveMQ\Queue\Config;
use Elielelie\ActiveMQ\Queue\ConnectionWrapper;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\ServiceProvider;
use Psr\Log\NullLogger;

class ActiveMQServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/log-activemq.php', 'log-activemq');

        $this->mergeConfigFrom(__DIR__ . '/../config/activemq.php', 'queue.connections.activemq');
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        app()->singleton(Config::class);
        app()->singleton(ConnectionWrapper::class);
        app()->singleton(ClientWrapper::class);

        app()->singleton(ActiveMQQueue::class);

        /** @var QueueManager $queue */
        $queue       = $this->app['queue'];

        $queue->addConnector('activemq', function () {
            return new StompConnector($this->app['events']);
        });

        $logsEnabled = Config::get('enable_logs');

        app()->singleton('activemqLog', function ($app) use ($logsEnabled) {
            $logManager = config('log-activemq.log_manager');

            return $logsEnabled ? new $logManager($app) : new NullLogger();
        });

        $this->registerPublishables();
        $this->registerCommands();
    }

    public function registerPublishables(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/activemq.php' => config_path('activemq.php'),
            ], 'activemq-config');
        }
    }

    public function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                TestActiveMQConnection::class,
            ]);
        }
    }
}
