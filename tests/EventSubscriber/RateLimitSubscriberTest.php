<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\RateLimitSubscriber;
use App\Service\RateLimitService;
use App\Tests\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class RateLimitSubscriberTest extends TestCase
{
    private RateLimitSubscriber $subscriber;
    private $rateLimitServiceMock;
    private $loggerMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rateLimitServiceMock = $this->createMock(RateLimitService::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->subscriber = new RateLimitSubscriber(
            $this->rateLimitServiceMock,
            $this->loggerMock
        );
    }

    public function testOnKernelRequestAllowed(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'app_payment_create');

        $kernel = $this->createMock(HttpKernelInterface::class);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->rateLimitServiceMock->expects($this->once())
            ->method('checkRateLimit')
            ->with($request, 'create_payment')
            ->willReturn(null); // No rate limit response

        $this->subscriber->onKernelRequest($event);

        // Event should not be stopped
        $this->assertFalse($event->hasResponse());
    }

    public function testOnKernelRequestRateLimited(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'app_payment_get');

        $kernel = $this->createMock(HttpKernelInterface::class);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $rateLimitResponse = new Response('Rate limited', 429);

        $this->rateLimitServiceMock->expects($this->once())
            ->method('checkRateLimit')
            ->with($request, 'get_payment')
            ->willReturn($rateLimitResponse);

        $this->subscriber->onKernelRequest($event);

        // Event should be stopped with rate limit response
        $this->assertTrue($event->hasResponse());
        $this->assertEquals(429, $event->getResponse()->getStatusCode());
    }

    public function testOnKernelRequestSubRequestIgnored(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'app_payment_create');

        $kernel = $this->createMock(HttpKernelInterface::class);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST);

        $this->rateLimitServiceMock->expects($this->never())
            ->method('checkRateLimit');

        $this->subscriber->onKernelRequest($event);

        $this->assertFalse($event->hasResponse());
    }

    public function testOnKernelRequestNoRouteIgnored(): void
    {
        $request = new Request();
        // No route attribute set

        $kernel = $this->createMock(HttpKernelInterface::class);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->rateLimitServiceMock->expects($this->never())
            ->method('checkRateLimit');

        $this->subscriber->onKernelRequest($event);

        $this->assertFalse($event->hasResponse());
    }

    public function testGetSubscribedEvents(): void
    {
        $subscribedEvents = $this->subscriber::getSubscribedEvents();

        $this->assertArrayHasKey('kernel.request', $subscribedEvents);
        $this->assertEquals('onKernelRequest', $subscribedEvents['kernel.request'][0]);
        $this->assertEquals(5, $subscribedEvents['kernel.request'][1]); // Priority
    }

    public function testRouteToEndpointMapping(): void
    {
        $testCases = [
            'app_payment_create' => 'create_payment',
            'app_payment_get' => 'get_payment',
            'app_payment_process' => 'process_payment',
            'unknown_route' => 'unknown_endpoint'
        ];

        $reflection = new \ReflectionClass($this->subscriber);
        $method = $reflection->getMethod('getEndpointFromRoute');
        $method->setAccessible(true);

        foreach ($testCases as $route => $expectedEndpoint) {
            $result = $method->invoke($this->subscriber, $route);
            $this->assertEquals($expectedEndpoint, $result, "Route '{$route}' should map to endpoint '{$expectedEndpoint}'");
        }
    }
}