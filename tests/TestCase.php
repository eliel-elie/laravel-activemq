<?php

namespace Elielelie\ActiveMQ\Tests;

use Elielelie\ActiveMQ\ActiveMQServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            ActiveMQServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('queue.default', 'activemq');
        $app['config']->set('queue.connections.activemq', [
            'driver'            => 'activemq',
            'host'              => '127.0.0.1',
            'port'              => 61613,
            'user'              => 'admin',
            'password'          => 'admin',
            'read_queues'       => 'default',
            'write_queues'      => 'default',
            'prepend_queues'    => false,
            'persistent_queues' => false,
        ]);
    }
}
