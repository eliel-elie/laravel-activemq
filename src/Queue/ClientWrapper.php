<?php

namespace Elielelie\ActiveMQ\Queue;

use Illuminate\Support\Str;
use Stomp\Client;
use Stomp\Exception\StompException;
use Stomp\Network\Observer\HeartbeatEmitter;
use Stomp\StatefulStomp;

class ClientWrapper
{
    public StatefulStomp $client;

    /**
     * ClientWrapper constructor.
     *
     * @param  ConnectionWrapper  $connectionWrapper
     *
     * @throws StompException
     */
    public function __construct(ConnectionWrapper $connectionWrapper)
    {
        $client = new Client($connectionWrapper->connection);
        $this->setCredentials($client);

        $client->setSync(false);
        $client->setHeartbeat(Config::get('send_heartbeat'), Config::get('receive_heartbeat'));
        $client->setClientId(Str::uuid()->toString());
        $client->setVersions(Config::get('version'));

        $emitter = new HeartbeatEmitter($client->getConnection());
        $client->getConnection()->getObservers()->addObserver($emitter);

        $this->client = new StatefulStomp($client);

        $client->connect();

    }

    protected function setCredentials(Client $client): void
    {
        $username = Config::get('username');
        $password = Config::get('password');

        if ($username && $password) {
            $client->setLogin($username, $password);
        }
    }
}