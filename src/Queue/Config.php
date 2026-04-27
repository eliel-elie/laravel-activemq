<?php

namespace Elielelie\ActiveMQ\Queue;

use Illuminate\Support\Str;

class Config
{
    const CONFIG_ARRAY_PATH = 'queue.connections.activemq.';

    public static function get($key)
    {
        // Try to get from the specific activemq connection first
        $value = config(self::CONFIG_ARRAY_PATH . $key);

        // Fallback to the top-level activemq config if not found in the connection
        if ($value === null) {
            $value = config('activemq.' . $key);
        }

        return $value;
    }

    public static function defaultQueue(): string
    {
        return self::get('default_queue') ?: 'default';
    }

    public static function readQueues(): string
    {
        return self::get('read_queues') ?: 'default';
    }

    public static function writeQueues(): string
    {
        return self::get('write_queues') ?: 'default';
    }

    protected static function appName(): string
    {
        return Str::snake(config('app.name', 'localhost'));
    }

    public static function getPersistent(): bool
    {
        return self::get('persistent_queues') === true ||
               self::get('persistent') === true ||
               filter_var(env('ACTIVEMQ_PERSISTENT', false), FILTER_VALIDATE_BOOLEAN);
    }
}
