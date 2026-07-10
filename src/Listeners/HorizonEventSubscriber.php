<?php

namespace Elielelie\ActiveMQ\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobQueued;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\JobPayload;

class HorizonEventSubscriber
{
    /**
     * The job repository implementation.
     */
    public JobRepository $jobs;

    /**
     * The metrics repository implementation.
     *
     * @var MetricsRepository
     */
    public $metrics;

    /**
     * Create a new event subscriber instance.
     *
     * @return void
     */
    public function __construct(JobRepository $jobs, MetricsRepository $metrics)
    {
        $this->jobs    = $jobs;
        $this->metrics = $metrics;
    }

    /**
     * Register the listeners for the subscriber.
     */
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(JobQueued::class, [$this, 'handleJobPushed']);
        $events->listen(JobProcessed::class, [$this, 'handleJobProcessed']);
        $events->listen(JobFailed::class, [$this, 'handleJobFailed']);
    }

    /**
     * Handle the "job pushed" event.
     */
    public function handleJobPushed(JobQueued $event): void
    {
        if ($event->connectionName !== 'activemq') {
            return;
        }

        $queue = property_exists($event, 'queue')
            ? $event->queue
            : (json_decode($event->payload, true)['queue'] ?? 'default');

        $this->jobs->pushed(
            $event->connectionName,
            $queue,
            new JobPayload($event->payload)
        );
    }

    /**
     * Handle the "job processed" event.
     */
    public function handleJobProcessed(JobProcessed $event): void
    {
        if ($event->connectionName !== 'activemq') {
            return;
        }

        $payload = new JobPayload($event->job->getRawBody());

        $this->jobs->completed($payload);

        $runtime = (microtime(true) - ($payload->decode()['pushedAt'] ?? microtime(true))) * 1000;

        $this->metrics->incrementJob($payload->decode()['displayName'] ?? $event->job->resolveName(), $runtime);
        $this->metrics->incrementQueue($event->job->getQueue(), $runtime);
    }

    /**
     * Handle the "job failed" event.
     */
    public function handleJobFailed(JobFailed $event): void
    {
        if ($event->connectionName !== 'activemq') {
            return;
        }

        $this->jobs->failed(
            $event->exception,
            $event->connectionName,
            $event->job->getQueue(),
            new JobPayload($event->job->getRawBody())
        );
    }
}
