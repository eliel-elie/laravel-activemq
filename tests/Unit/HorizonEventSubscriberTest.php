<?php

namespace Laravel\Horizon\Contracts {
    if (! interface_exists(JobRepository::class)) {
        interface JobRepository
        {
            public function pushed($connection, $queue, $payload);

            public function completed($payload, $failed = false, $silenced = false);

            public function failed($exception, $connection, $queue, $payload);
        }
    }

    if (! interface_exists(MetricsRepository::class)) {
        interface MetricsRepository
        {
            public function incrementJob($displayName, $runtime);

            public function incrementQueue($queue, $runtime);
        }
    }
}

namespace Laravel\Horizon {
    if (! class_exists(JobPayload::class)) {
        class JobPayload
        {
            public $value;

            public $decoded;

            public function __construct($value)
            {
                $this->value   = $value;
                $this->decoded = is_array($value) ? $value : json_decode($value, true);
            }

            public function decode()
            {
                return $this->decoded;
            }
        }
    }
}

namespace Elielelie\ActiveMQ\Tests\Unit {

    use Elielelie\ActiveMQ\ActiveMQServiceProvider;
    use Elielelie\ActiveMQ\Listeners\HorizonEventSubscriber;
    use Exception;
    use Illuminate\Contracts\Events\Dispatcher;
    use Illuminate\Contracts\Queue\Job;
    use Illuminate\Queue\Events\JobFailed;
    use Illuminate\Queue\Events\JobProcessed;
    use Illuminate\Queue\Events\JobQueued;
    use Laravel\Horizon\Contracts\JobRepository;
    use Laravel\Horizon\Contracts\MetricsRepository;
    use Laravel\Horizon\JobPayload;
    use Mockery;
    use ReflectionClass;

    function createJobQueuedEvent(string $connectionName, string $queue, string $id, string $job, string $payload): JobQueued
    {
        $event                 = (new ReflectionClass(JobQueued::class))->newInstanceWithoutConstructor();
        $event->connectionName = $connectionName;
        $event->id             = $id;
        $event->job            = $job;
        $event->payload        = $payload;

        if (property_exists($event, 'queue')) {
            $event->queue = $queue;
        }

        return $event;
    }

    beforeEach(function () {
        $this->mockJobRepository     = Mockery::mock(JobRepository::class);
        $this->mockMetricsRepository = Mockery::mock(MetricsRepository::class);

        $this->app->instance(JobRepository::class, $this->mockJobRepository);
        $this->app->instance(MetricsRepository::class, $this->mockMetricsRepository);
    });

    it('subscribes to the correct events', function () {
        $subscriber = new HorizonEventSubscriber($this->mockJobRepository, $this->mockMetricsRepository);

        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('listen')->once()->with(JobQueued::class, [$subscriber, 'handleJobPushed']);
        $dispatcher->shouldReceive('listen')->once()->with(JobProcessed::class, [$subscriber, 'handleJobProcessed']);
        $dispatcher->shouldReceive('listen')->once()->with(JobFailed::class, [$subscriber, 'handleJobFailed']);

        $subscriber->subscribe($dispatcher);
    });

    it('registers subscriber via provider if JobRepository is bound and enabled in config', function () {
        config(['queue.connections.activemq.horizon.enabled' => true]);

        // Let's boot a new provider to see if it registers the subscriber
        $provider   = new ActiveMQServiceProvider($this->app);

        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('subscribe')->once()->with(HorizonEventSubscriber::class);

        $this->app->instance('events', $dispatcher);

        $provider->boot();
    });

    it('does not register subscriber via provider if horizon is disabled in config', function () {
        config(['queue.connections.activemq.horizon.enabled' => false]);

        // Let's boot a new provider
        $provider   = new ActiveMQServiceProvider($this->app);

        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldNotReceive('subscribe');

        $this->app->instance('events', $dispatcher);

        $provider->boot();
    });

    it('handles job queued event for activemq', function () {
        $subscriber = new HorizonEventSubscriber($this->mockJobRepository, $this->mockMetricsRepository);

        $payload    = '{"uuid":"123","displayName":"MyJob","queue":"default"}';
        $event      = createJobQueuedEvent('activemq', 'default', '123', 'MyJobClass', $payload);

        $this->mockJobRepository->shouldReceive('pushed')->once()->with(
            'activemq',
            'default',
            Mockery::on(function ($jobPayload) use ($payload) {
                return $jobPayload instanceof JobPayload && $jobPayload->value === $payload;
            })
        );

        $subscriber->handleJobPushed($event);
    });

    it('ignores job queued event for other connections', function () {
        $subscriber = new HorizonEventSubscriber($this->mockJobRepository, $this->mockMetricsRepository);

        $event      = createJobQueuedEvent('other', 'default', '123', 'MyJobClass', '{"queue":"default"}');

        $this->mockJobRepository->shouldNotReceive('pushed');

        $subscriber->handleJobPushed($event);
    });

    it('handles job processed event for activemq', function () {
        $subscriber = new HorizonEventSubscriber($this->mockJobRepository, $this->mockMetricsRepository);

        $job        = Mockery::mock(Job::class);
        $rawBody    = json_encode(['uuid' => '123', 'displayName' => 'MyJob', 'pushedAt' => microtime(true) - 0.5]);
        $job->shouldReceive('getRawBody')->andReturn($rawBody);
        $job->shouldReceive('getQueue')->andReturn('default');

        $event      = new JobProcessed('activemq', $job);

        $this->mockJobRepository->shouldReceive('completed')->once()->with(
            Mockery::on(function ($jobPayload) use ($rawBody) {
                return $jobPayload instanceof JobPayload && $jobPayload->value === $rawBody;
            })
        );

        $this->mockMetricsRepository->shouldReceive('incrementJob')->once()->with('MyJob', Mockery::type('float'));
        $this->mockMetricsRepository->shouldReceive('incrementQueue')->once()->with('default', Mockery::type('float'));

        $subscriber->handleJobProcessed($event);
    });

    it('ignores job processed event for other connections', function () {
        $subscriber = new HorizonEventSubscriber($this->mockJobRepository, $this->mockMetricsRepository);

        $event      = new JobProcessed('other', Mockery::mock(Job::class));

        $this->mockJobRepository->shouldNotReceive('completed');
        $this->mockMetricsRepository->shouldNotReceive('incrementJob');

        $subscriber->handleJobProcessed($event);
    });

    it('handles job failed event for activemq', function () {
        $subscriber = new HorizonEventSubscriber($this->mockJobRepository, $this->mockMetricsRepository);

        $job        = Mockery::mock(Job::class);
        $rawBody    = json_encode(['uuid' => '123', 'displayName' => 'MyJob']);
        $job->shouldReceive('getRawBody')->andReturn($rawBody);
        $job->shouldReceive('getQueue')->andReturn('default');
        $exception  = new Exception('Job failed');

        $event      = new JobFailed('activemq', $job, $exception);

        $this->mockJobRepository->shouldReceive('failed')->once()->with(
            $exception,
            'activemq',
            'default',
            Mockery::on(function ($jobPayload) use ($rawBody) {
                return $jobPayload instanceof JobPayload && $jobPayload->value === $rawBody;
            })
        );

        $subscriber->handleJobFailed($event);
    });

    it('ignores job failed event for other connections', function () {
        $subscriber = new HorizonEventSubscriber($this->mockJobRepository, $this->mockMetricsRepository);

        $event      = new JobFailed('other', Mockery::mock(Job::class), new Exception('Job failed'));

        $this->mockJobRepository->shouldNotReceive('failed');

        $subscriber->handleJobFailed($event);
    });
}
