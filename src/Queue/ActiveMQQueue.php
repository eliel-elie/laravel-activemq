<?php

namespace Elielelie\ActiveMQ\Queue;

use Closure;
use DateInterval;
use DateTimeInterface;
use Elielelie\ActiveMQ\Contracts\HasHeaders;
use Elielelie\ActiveMQ\Contracts\HasRawData;
use Elielelie\ActiveMQ\Helpers\IntervalToMilliseconds;
use Elielelie\ActiveMQ\Jobs\ActiveMQJob;
use Exception;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Queue\InvalidPayloadException;
use Illuminate\Queue\Queue;
use Illuminate\Contracts\Queue\Queue as QueueInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Stomp\Exception\ConnectionException;
use Stomp\StatefulStomp;
use Stomp\Transport\Frame;
use Stomp\Transport\Message;

class ActiveMQQueue extends Queue implements QueueInterface
{
    public const AMQ_QUEUE_SEPARATOR = '::';
    public const HEADERS_KEY         = '_headers';
    const CORRELATION                = 'X-Correlation-ID';

    /**
     * Stomp instance from stomp-php repo.
     */
    public StatefulStomp $client;

    public array $readQueues;
    public array $writeQueues;

    /**
     * List of queues already subscribed to. Preventing multiple same subscriptions.
     *
     * @var array
     */
    protected array $subscribedTo = [];

    protected LoggerInterface $log;
    protected static int $circuitBreaker = 0;
    protected string $session;

    public function __construct(ClientWrapper $stompClient)
    {
        $this->readQueues   = $this->setReadQueues();
        $this->writeQueues  = $this->setWriteQueues();
        $this->client       = $stompClient->client;
        $this->log          = app('activemqLog');

        $this->session = $this->client->getClient()->getSessionId();
    }

    /**
     * Append queue name to topic/address to avoid random hashes in broker.
     *
     * @return array
     */
    protected function setReadQueues(): array
    {
        $queues = $this->parseQueues(Config::readQueues());

        foreach ($queues as &$queue) {
            $default = Config::defaultQueue();

            if (!str_contains($queue, self::AMQ_QUEUE_SEPARATOR)) {
                continue;
            }

            if (Config::get('prepend_queues')) {
                $topic     = Str::before($queue, self::AMQ_QUEUE_SEPARATOR);
                $queueName = Str::after($queue, self::AMQ_QUEUE_SEPARATOR);

                $queue = $topic . self::AMQ_QUEUE_SEPARATOR . "{$topic}_{$default}_{$queueName}";
            }
        }

        return $queues;
    }

    protected function setWriteQueues(): array
    {
        return $this->parseQueues(Config::writeQueues());
    }

    /**
     * Get the size of the queue.
     *
     * @param  string|null  $queue
     * @return int
     */
    public function size($queue = null)
    {
        return 0;
    }

    /**
     * Push a new job onto the queue.
     *
     * @param  string|object  $job
     * @param  mixed  $data
     * @param  string|null  $queue
     * @return mixed
     */
    public function push($job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job, $queue, $data), $queue);
    }

    /**
     * Push a new job onto the queue after a delay.
     *
     * @param  DateTimeInterface|DateInterval|int  $delay
     * @param  string|object  $job
     * @param  mixed  $data
     * @param  string|null  $queue
     * @return mixed
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job, $data, $queue), $queue);
    }

    /**
     * Push a raw payload onto the queue.
     *
     * @param  mixed  $payload
     * @param  string|null  $queue
     * @param  array  $options
     * @return mixed
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        if (!$payload instanceof Message) {
            $payload = $this->wrapStompMessage($payload);
        }

        $payload = $this->addCorrelationHeader($payload);

        $writeQueues = $queue ? $this->parseQueues($queue) : $this->writeQueues;

        return $this->writeToMultipleQueues($writeQueues, $payload);
    }

    /**
     * @param  Frame  $payload
     * @return mixed
     */
    protected function addCorrelationHeader($payload)
    {
        if (!$this->needsHeader($payload, self::CORRELATION)) {
            return $payload;
        }

        $header = Str::uuid()->toString();

        if (request()->hasHeader(self::CORRELATION)) {
            $header = request()->header(self::CORRELATION);
        }

        $payload->addHeaders([self::CORRELATION => $header]);

        return $payload;
    }

    /**
     * @param  Frame  $payload
     * @param  string  $header
     * @return bool
     */
    protected function needsHeader($payload, string $header): bool
    {
        $headers = $payload->getHeaders();

        return !Arr::has($headers, [$header]);
    }

    protected function wrapStompMessage(string $payload): Message
    {
        $decoded    = json_decode($payload, true);
        $body       = Arr::except($decoded, self::HEADERS_KEY);
        $body       = $this->addMissingUuid($body);
        $headers    = Arr::get($decoded, self::HEADERS_KEY, []);
        $headers    = $this->forgetHeadersForRedelivery($headers);

        return new Message(json_encode($body), $headers);
    }

    /**
     * @param  array  $writeQueues
     * @param  Message  $payload
     * @return bool
     */
    protected function writeToMultipleQueues(array $writeQueues, Message $payload): bool
    {
        /**
         * @var $payload Message
         */
        $this->log->info("$this->session [STOMP] Pushing stomp payload to queue: " . print_r([
                'body'    => $payload->getBody(),
                'headers' => $payload->getHeaders(),
                'queue'   => $writeQueues,
            ], true));

        $allEventsSent = true;

        foreach ($writeQueues as $writeQueue) {
            $sent = $this->write($writeQueue, $payload);

            if (!$sent) {
                $allEventsSent = false;
                $this->log->error("$this->session [STOMP] Message not sent on queue: $writeQueue");
                continue;
            }

            $this->log->info("$this->session [STOMP] Message sent on queue: $writeQueue");
        }

        return $allEventsSent;
    }

    protected function write($queue, Message $payload): bool
    {
        // This will write all the events received in a single batch, then send disconnect frame
        try {
            $this->log->info("$this->session [STOMP] PUSH queue: '$queue'");
            $sent = $this->client->send($queue, $payload);
            $this->log->info("$this->session [STOMP] Message sent successfully? " . ($sent ? 't' : 'f'));

            return $sent;
        } catch (Exception $ex) {
            $this->log->error("$this->session [STOMP] PUSH failed. Reconnecting...");
            $this->reconnect(false);

            $this->log->info("$this->session [STOMP] Trying to send again...");

            return $this->write($queue, $payload);
        }
    }

    /**
     * Overridden to prevent double json encoding/decoding
     * Create a payload string from the given job and data.
     *
     * @param $job
     * @param $queue
     * @param  string  $data
     * @return Message
     */
    protected function createPayload($job, $queue, $data = '')
    {
        if ($job instanceof Closure) {
            $job = CallQueuedClosure::create($job);
        }

        $payload = $this->createPayloadArray($job, $queue, $data);
        $payload = $this->addMissingUuid($payload);
        $headers = $this->getHeaders($job);
        $headers = $this->forgetHeadersForRedelivery($headers);

        $headers = $this->setDelayQueue($job, $headers);

        $message = new Message(json_encode($payload), $headers);

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new InvalidPayloadException(
                'Unable to JSON encode payload. Error code: ' . json_last_error()
            );
        }

        return $message;
    }

    /**
     * @param $job
     * @return array
     */
    protected function setDelayQueue($job, $headers): array
    {
        if($job->delay) {

            $schedule = 0;

            if ($job->delay instanceof DateInterval) {
                $schedule = IntervalToMilliseconds::convert($job->delay);
            }

            if ($job->delay instanceof DateTimeInterface) {
                $schedule = IntervalToMilliseconds::convert(now()->diff($job->delay));
            }

            if (is_int($job->delay)) {
                $schedule = $job->delay * 1000;
            }

            $headers =  array_merge($headers, ['AMQ_SCHEDULED_DELAY' => $schedule]);
        }

        return $headers;
    }

    /**
     * Overridden to support raw data
     * Create a payload array from the given job and data.
     *
     * @param  object|string  $job
     * @param  string  $queue
     * @param  string  $data
     * @return array
     */
    protected function createPayloadArray($job, $queue, $data = '')
    {
        if ($this->hasEvent($job) && $job->event instanceof HasRawData) {
            return $job->event->getRawData();
        }

        if ($job instanceof HasRawData) {
            return $job->getRawData();
        }

        return parent::createPayloadArray($job, $queue, $data);
    }

    protected function addMissingUuid(array $payload): array
    {
        if (!Arr::has($payload, 'uuid')) {
            $payload['uuid'] = (string) Str::uuid();
        }

        return $payload;
    }

    protected function getHeaders($job)
    {
        if ($this->hasEvent($job) && $job->event instanceof HasHeaders) {
            return $job->event->getHeaders();
        }

        if ($job instanceof HasHeaders) {
            return $job->getHeaders();
        }

        return [];
    }

    protected function hasEvent($job): bool
    {
        return $job instanceof BroadcastEvent && property_exists($job, 'event');
    }

    /**
     * Pop the next job off of the queue.
     *
     * @param  string|null  $queue
     * @return ActiveMQJob
     */
    public function pop($queue = null)
    {
        $frame = $this->read($queue);

        if (!($frame instanceof Frame)) {
            return null;
        }

        $this->addCorrelationHeader($frame);

        $this->log->info("$this->session [STOMP] Popped a job (frame) from queue: " . print_r($frame, true));

        // This might no longer be relevant. It would happen if pop read CONNECTED frame instead of
        // MESSAGE one, which was a bug introduced at one point. Keeping it as safety measure
        $queueFromFrame = $this->getQueueFromFrame($frame);

        if (!$queueFromFrame) {
            $this->log->error("$this->session [STOMP] Wrong frame received. Expected MESSAGE, got: " . print_r($frame, true));

            return null;
        }

        return new ActiveMQJob($this->container, $this, $frame, $queueFromFrame);
    }

    protected function read($queue)
    {
        // This will read from queue, then push on same session ID if there are events following, then delete event which was read
        // If job fails, it will be re-pushed on same session ID but with additional headers for redelivery
        try {
            $this->log->info("$this->session [STOMP] POP");

            $this->heartbeat();
            $this->subscribeToQueues();

            $frame = $this->client->read();
            $this->log->info("$this->session [STOMP] Message read!");

            return $frame;
        } catch (Exception $e) {
            $this->log->error("$this->session [STOMP] Stomp failed to read any data from '$queue' queue. " . $e->getMessage());
            $this->reconnect();

            // Need a recursive call as otherwise it loses the original connection, losing the events in the process
            // NOT WORKING though...
            $this->log->info("$this->session [STOMP] Re-reading...");

            return $this->read($queue);
        }
    }

    protected function parseQueues(string $queue): array
    {
        return explode(';', $queue);
    }

    protected function getQueueFromFrame(Frame $frame): ?string
    {
        // This will return bool when accepting things like CONNECTED frame, we'd just want to
        $subscription = $this->client->getSubscriptions()->getSubscription($frame);

        return optional($subscription)->getDestination();
    }

    public function makeDelayHeader(int $delay): array
    {
        return ['AMQ_SCHEDULED_DELAY' => $delay * 1000];
    }

    /**
     * If these values are left in the header, it will screw up the whole event redelivery
     * so we need to remove them before sending back to queue.
     *
     * @param  array  $headers
     * @return array
     */
    public function forgetHeadersForRedelivery(array $headers): array
    {
        $keys = array_keys($headers);
        $amqMatches = preg_grep('/_AMQ.*/i', $keys);

        Arr::forget($headers, array_merge($amqMatches, ['content-length']));

        return $headers;
    }

    /**
     * @throws ConnectionException
     */
    protected function heartbeat(): void
    {
        $this->client->getClient()->getConnection()->sendAlive();
        $this->log->info("$this->session [STOMP] Sent alive to {$this->client->getClient()->getSessionId()}");
    }

    protected function reconnect(bool $subscribe = true)
    {
        $this->log->info("$this->session [STOMP] Reconnecting...");

        $this->disconnect();

        try {
            $this->client->getClient()->connect();

            $this->log->info("$this->session [STOMP] Reconnected successfully.");
        } catch (Exception $e) {
            self::$circuitBreaker++;
            $cb = self::$circuitBreaker;

            $this->log->error("$this->session [STOMP] Failed reconnecting (tries: $cb), retrying in 2s..." . print_r($e->getMessage(), true));

            if (self::$circuitBreaker <= 5) {
                $this->reconnect($subscribe);
            }

            $this->log->error("$this->session [STOMP] Circuit breaker executed after $cb tries, exiting.");

            return;
        }

        // By this point it should be connected, so it is safe to subscribe
        if ($subscribe) {
            $this->log->info("$this->session [STOMP] Connected, subscribing...");
            $this->subscribedTo = [];
            $this->subscribeToQueues();
        }
    }

    public function disconnect()
    {
        if (!$this->client->getClient()->isConnected()) {
            return;
        }

        try {
            $this->log->info("$this->session [STOMP] Disconnecting...");
            $this->client->getClient()->disconnect();
        } catch (Exception $e) {
            $this->log->info("$this->session [STOMP] Failed disconnecting: " . print_r($e->getMessage(), true));
        }
    }

    protected function subscribeToQueues(): void
    {
        foreach ($this->readQueues as $queue) {
            $alreadySubscribed = in_array($queue, $this->subscribedTo);

            if ($alreadySubscribed) {
                continue;
            }

            $this->client->subscribe($queue, null, 'auto', [
                // New Artemis version can't work without this as it will consume only first message otherwise.
                'consumer-window-size' => '-1',
            ]);

            $this->subscribedTo[] = $queue;
        }
    }
}
