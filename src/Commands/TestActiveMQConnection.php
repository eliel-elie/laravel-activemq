<?php

namespace Elielelie\ActiveMQ\Commands;

use Illuminate\Console\Command;
use Stomp\Client;
use Stomp\Exception\ConnectionException;

class TestActiveMQConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature   = 'activemq:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the connection to the ActiveMQ broker.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Testing ActiveMQ connection...');

        $host      = config('activemq.host');
        $port      = config('activemq.port');
        $user      = config('activemq.username');
        $password  = config('activemq.password');
        $protocol  = config('activemq.protocol');

        $start     = microtime(true);
        $connected = false;
        $message   = '';

        try {
            $client = new Client("{$protocol}://{$host}:{$port}");
            $client->setLogin($user, $password);
            $client->connect();

            if ($client->isConnected()) {
                $connected = true;
                $message   = 'Successfully connected.';
                $client->disconnect();
            }
        } catch (ConnectionException $e) {
            $message = $e->getMessage();
        }

        $tested[]  = [
            'activemq',
            $connected ? '✔ Yes' : '✘ No',
            "{$protocol}://{$host}:{$port}",
            $message,
            (app()->runningUnitTests() ? '0' : $this->getElapsedTime($start)) . 'ms',
        ];

        $this->table(['Connection', 'Successful', 'Hostname', 'Message', 'Response Time'], $tested);

        return $connected ? Command::SUCCESS : Command::FAILURE;
    }

    protected function getElapsedTime($start): float
    {
        return round((microtime(true) - $start) * 1000, 2);
    }
}
