<?php

use Elielelie\ActiveMQ\Queue\ActiveMQQueue;
use Elielelie\ActiveMQ\Queue\ClientWrapper;
use Stomp\Client as StompClient;
use Stomp\StatefulStomp;
use Stomp\Transport\Message;

beforeEach(function () {
    $this->mockClient                = mock(StompClient::class);
    $this->mockClient->shouldReceive('getSessionId')->andReturn('mock-session-id');

    $this->mockStatefulStomp         = mock(StatefulStomp::class);
    $this->mockStatefulStomp->shouldReceive('getClient')->andReturn($this->mockClient);

    $this->mockClientWrapper         = mock(ClientWrapper::class);
    $this->mockClientWrapper->client = $this->mockStatefulStomp;
});

it('can instantiate ActiveMQQueue', function () {
    $queue = new ActiveMQQueue($this->mockClientWrapper);
    expect($queue)->toBeInstanceOf(ActiveMQQueue::class);
});

it('creates payload with delay parameter', function () {
    $queue      = new ActiveMQQueue($this->mockClientWrapper);

    $reflection = new ReflectionClass($queue);
    $method     = $reflection->getMethod('createPayload');
    $method->setAccessible(true);

    $delay      = 30; // 30 seconds
    /** @var Message $message */
    $message    = $method->invokeArgs($queue, ['MyJobClass', 'default-queue', ['foo' => 'bar'], $delay]);

    expect($message)->toBeInstanceOf(Message::class);
    $headers    = $message->getHeaders();

    expect($headers)->toHaveKey('AMQ_SCHEDULED_DELAY');
    expect($headers['AMQ_SCHEDULED_DELAY'])->toBe(30000);
});

it('falls back to job delay property if delay parameter is null', function () {
    $queue      = new ActiveMQQueue($this->mockClientWrapper);

    $reflection = new ReflectionClass($queue);
    $method     = $reflection->getMethod('createPayload');
    $method->setAccessible(true);

    $job        = new stdClass;
    $job->delay = 60; // 60 seconds

    /** @var Message $message */
    $message    = $method->invokeArgs($queue, [$job, 'default-queue', '', null]);

    expect($message)->toBeInstanceOf(Message::class);
    $headers    = $message->getHeaders();

    expect($headers)->toHaveKey('AMQ_SCHEDULED_DELAY');
    expect($headers['AMQ_SCHEDULED_DELAY'])->toBe(60000);
});

it('later method forwards the delay to createPayload', function () {
    $this->mockStatefulStomp->shouldReceive('send')->once()->with('default-queue', Mockery::type(Message::class))->andReturn(true);

    $queue = new ActiveMQQueue($this->mockClientWrapper);

    $delay = 15; // 15 seconds
    $queue->later($delay, 'MyJobClass', ['data' => 'test'], 'default-queue');
});
