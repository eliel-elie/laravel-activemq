# Laravel ActiveMQ driver

<p align="left">
<a href="https://packagist.org/packages/elielelie/laravel-activemq"><img src="https://poser.pugx.org/elielelie/laravel-activemq/d/total.svg" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/elielelie/laravel-activemq"><img src="https://poser.pugx.org/elielelie/laravel-activemq/v/stable.svg" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/elielelie/laravel-activemq"><img src="https://poser.pugx.org/elielelie/laravel-activemq/license.svg" alt="License"></a>
</p>

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
ACTIVEMQ_QUEUE=...
```
```
default;email 
```

You can see all other available `.env` variables, their defaults and usage explanation within the [config file](config/activemq.php)

You can publish the configuration file by running:

```$ php artisan vendor:publish --provider="Elielelie\ActiveMQ\ActiveMQServiceProvider" ```

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

## Laravel Horizon Support

This package now supports Laravel Horizon for advanced queue management with multiple workers and auto-scaling capabilities.

### Configuration

To enable Horizon support, add these environment variables:

```
ACTIVEMQ_HORIZON_ENABLED=true
ACTIVEMQ_HORIZON_ISOLATE=true
```

### Horizon Configuration Example

Add this configuration to your `config/horizon.php`:

```php
'environments' => [
    'production' => [
        'activemq-default' => [
            'connection' => 'activemq',
            'queue' => ['default'],
            'balance' => 'simple',
            'processes' => 3,
        ],
        'activemq-emails' => [
            'connection' => 'activemq', 
            'queue' => ['email'],
            'balance' => 'auto',
            'processes' => 2,
            'maxProcesses' => 5,
        ],
        'activemq-notifications' => [
            'connection' => 'activemq',
            'queue' => ['notifications'],
            'balance' => 'simple', 
            'processes' => 1,
        ],
    ],
],
```

### Multiple Queue Setup

You can define multiple queues in your environment configuration:

```
ACTIVEMQ_QUEUE=default;email;notifications
```

Each supervisor in Horizon can process specific queues, allowing for:
- **Worker Isolation**: Different workers for different queue types
- **Auto-Scaling**: Automatic worker scaling based on queue load
- **Priority Processing**: Different process counts and strategies per queue

### Balancing Strategies

- **simple**: Fixed number of workers distributed evenly
- **auto**: Dynamic worker allocation based on queue load
- **false**: Process queues in order without balancing

## Logs

Logs are turned off by default. You can include them by setting env key `ACTIVEMQ_LOGS=true`.

In case you want to change the default log manager, it can be done in the `log-activemq` config file. The new log manager must extend `Illuminate\Log\LogManager`.
