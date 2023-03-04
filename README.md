# Laravel ActiveMQ driver

This package enables usage of ActiveMQ (Stomp) driver for queueing natively inside Laravel.

## Installation

``` composer require elielelie/laravel-activemq ```

In order to connect it to your queue you need to change queue connection driver in .env file:

```
QUEUE_CONNECTION=activemq
```

Variables:

```
ACTIVEMQ_PROTOCOL      protocol (defaults to TCP)
ACTIVEMQ_HOST          broker host (defaults to 127.0.0.1)
ACTIVEMQ_PORT          port where STOMP is exposed in your broker (defaults to 61613)
ACTIVEMQ_USERNAME      broker username (defaults to admin)
ACTIVEMQ_PASSWORD      broker password (defaults to admin)
```

You can subscribe to queues to read from or to write to with:

```
STOMP_READ_QUEUES=...
STOMP_WRITE_QUEUES=...
```
```
default;email 
```

You can see all other available `.env` variables, their defaults and usage explanation within the [config file](config/activemq.php)

## Failed jobs

For the sake of simplicity and brevity ActiveMQJob class is defined in a way to utilize Laravel tries and backoff properties out of the box ([official documentation](https://laravel.com/docs/8.x/queues#dealing-with-failed-jobs)).

Upon failure, jobs will retry 5 times before being written to failed_jobs table.

Each subsequent attempt will be tried in attempt^2 seconds, meaning if it is a third attempt, it will retry in 9s after the previous job failure.

Note that job properties by default have precedence over CLI commands, thus with these defaults in place the flags `--tries` and `--backoff` will be overridden.

You can turn off this behavior with following `.env` variables:

* `ACTIVEMQ_AUTO_TRIES` - defaults to `true`. Set to `false` to revert to Laravel default of 0 retries.
* `ACTIVEMQ_AUTO_BACKOFF` - defaults to `true`. Set to `false` to revert to Laravel default of 0s backoff.
* `ACTIVEMQ_BACKOFF_MULTIPLIER` - defaults to `2`. Does nothing if `ACTIVEMQ_AUTO_BACKOFF` is turned off. Increase to make even bigger interval between two failed jobs.

Job will be re-queued to the queue it came from.

## Logs

Logs are turned off by default. You can include them by setting env key `ACTIVEMQ_LOGS=true`.

In case you want to change the default log manager, it can be done in the `log-activemq` config file. The new log manager must extend `Illuminate\Log\LogManager`.
