<?php

use Stomp\Protocol\Version;

return [

    'driver'        => 'stomp',
    'read_queues'   => env('ACTIVEMQ_READ_QUEUES'),
    'write_queues'  => env('ACTIVEMQ_WRITE_QUEUES'),
    'protocol'      => env('ACTIVEMQ_PROTOCOL', 'tcp'),
    'host'          => env('ACTIVEMQ_HOST', '127.0.0.1'),
    'port'          => env('ACTIVEMQ_PORT', 61613),
    'username'      => env('ACTIVEMQ_USERNAME', 'admin'),
    'password'      => env('ACTIVEMQ_PASSWORD', 'admin'),

    /**
     * Set to "horizon" if you wish to use Laravel Horizon.
     */
    'worker'        => env('ACTIVEMQ_WORKER', 'default'),

    /**
     * Calculate tries and backoff automatically without the need to specify it
     * in the queue work command.
     */
    'auto_tries'    => env('ACTIVEMQ_AUTO_TRIES', true),
    'auto_backoff'  => env('ACTIVEMQ_AUTO_BACKOFF', true),

    /**
     * If all messages should fail on timeout.
     * Set to false in order to revert to default (looking in event payload)
     */
    'fail_on_timeout' => env('ACTIVEMQ_FAIL_ON_TIMEOUT', true),

    /**
     * Maximum time in seconds for job execution.
     * This value must be less than send heartbeat in order to run correctly.
     */
    'timeout'       => env('ACTIVEMQ_TIMEOUT', 10),

    /**
     * Incremental multiplier for failed job redelivery.
     * Multiplier 2 means that the rescheduled job will be repeated in attempt^2 seconds.
     *
     * For 3 tries this means:
     *
     * attempt 2 - 4s
     * attempt 3 - 9s
     * attempt 4 - 16s
     */
    'backoff_multiplier' => env('STOMP_BACKOFF_MULTIPLIER', 2),

    /**
     * What will be appended as the queue if only address/topic is defined.
     * This is done to ensure that service doesn't connect to a broker with
     * hash as queue name. In case of multiple services connecting in such
     * a way, it becomes unclear which queue is from which service.
     */
    'default_queue'     => env('ACTIVEMQ_DEFAULT_QUEUE'),

    /**
     * Use Laravel logger for outputting logs.
     */
    'enable_logs'       => env('ACTIVEMQ_LOGS', false) === true,

    /**
     * Should the read queues be prepended. Useful for i.e. Artemis where queue
     * name is unique across whole broker instance. This will thus add some
     * uniqueness to the queues.
     */
    'prepend_queues'    => true,

    /**
     * Heartbeat which will be requested from server at given millisecond period.
     */
    'receive_heartbeat' => env('ACTIVEMQ_RECEIVE_HEARTBEAT', 0),

    /**
     * Heartbeat which we will be sending to server at given millisecond period.
     */
    'send_heartbeat'    => env('ACTIVEMQ_SEND_HEARTBEAT', 20000),

    /**
     * Array of supported versions.
     */
    'version'           => [Version::VERSION_1_2],
];
