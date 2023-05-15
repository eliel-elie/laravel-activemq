<?php

namespace Elielelie\ActiveMQ\Facades;

use Elielelie\ActiveMQ\Queue\ActiveMQQueue;
use Illuminate\Support\Facades\Facade;

class ActiveMQ extends Facade
{
    /**
     * Return facade accessor
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return ActiveMQQueue::class;
    }
}
