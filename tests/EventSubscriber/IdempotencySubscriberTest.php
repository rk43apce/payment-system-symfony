<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\IdempotencySubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class IdempotencySubscriberTest extends TestCase
{
    private IdempotencySubscriber $subscriber;

    protected function setUp(): void
    {
        $this->subscriber = new IdempotencySubscriber(new NullLogger());
    }

    private function makeEvent(string $method, string $key = ''): RequestEvent
    {
        $request = Request::create('/transfer', $method);
        if ($key !== '') {
            $request->headers->set('Idempotency-Key', $key);
        }
        $kernel = $this->createMock(HttpKernelInterface::class);
        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    public function testGetRequestPassesThrough(): void
    {
        $event = $this->makeEvent('GET');
        $this->subscriber->onKernelRequest($event);
        $this->assertNull($event->getResponse());
    }

    public function testPostWithValidKeyPassesThrough(): void
    {
        $event = $this->makeEvent('POST', 'valid-key-12345');
        $this->subscriber->onKernelRequest($event);
        $this->assertNull($event->getResponse());
    }

    public function testPostWithMissingKeyReturns422(): void
    {
        $event = $this->makeEvent('POST');
        $this->subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(422, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertSame('missing_idempotency_key', $body['error']['code']);
    }

    public function testPostWithTooShortKeyReturns422(): void
    {
        $event = $this->makeEvent('POST', 'short');
        $this->subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(422, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertSame('invalid_idempotency_key', $body['error']['code']);
    }

    public function testPostWithTooLongKeyReturns422(): void
    {
        $event = $this->makeEvent('POST', str_repeat('a', 65));
        $this->subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testPostWithExactly8CharKeyPassesThrough(): void
    {
        $event = $this->makeEvent('POST', '12345678');
        $this->subscriber->onKernelRequest($event);
        $this->assertNull($event->getResponse());
    }

    public function testPostWithExactly64CharKeyPassesThrough(): void
    {
        $event = $this->makeEvent('POST', str_repeat('a', 64));
        $this->subscriber->onKernelRequest($event);
        $this->assertNull($event->getResponse());
    }
}
