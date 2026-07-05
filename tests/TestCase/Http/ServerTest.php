<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         3.3.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Test\TestCase\Http;

use Cake\Cache\Cache;
use Cake\Core\HttpApplicationInterface;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\Http\BaseApplication;
use Cake\Http\CallbackStream;
use Cake\Http\MiddlewareQueue;
use Cake\Http\Response;
use Cake\Http\ResponseEmitter;
use Cake\Http\Server;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\I18n\DateTime as I18nDateTime;
use Cake\I18n\I18n;
use Cake\I18n\Number;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use InvalidArgumentException;
use Laminas\Diactoros\Response as LaminasResponse;
use Laminas\Diactoros\ServerRequest as LaminasServerRequest;
use Mockery;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TestApp\Http\MiddlewareApplication;

require_once __DIR__ . '/server_mocks.php';

/**
 * Server test case
 */
class ServerTest extends TestCase
{
    /**
     * @var string
     */
    protected $config;

    /**
     * @var array
     */
    protected $server;

    /**
     * @var \Cake\Http\MiddlewareQueue
     */
    protected $middlewareQueue;

    /**
     * Setup
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->server = $_SERVER;
        $this->config = dirname(__DIR__, 2) . '/test_app/config';
        $GLOBALS['mockedHeaders'] = [];
        $GLOBALS['mockedHeadersSent'] = true;
    }

    /**
     * Teardown
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        $_SERVER = $this->server;
        unset($GLOBALS['mockedHeadersSent']);
        Cache::drop('server_worker_array');
    }

    /**
     * test get/set on the app
     */
    public function testAppGetSet(): void
    {
        $eventManager = new EventManager();
        $app = new class ($this->config, $eventManager) extends BaseApplication
        {
            public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
            {
                return $middlewareQueue;
            }
        };

        $server = new Server($app);
        $this->assertSame($app, $server->getApp());
        $this->assertSame($app->getEventManager(), $server->getEventManager());
    }

    /**
     * test run building a response
     */
    public function testRunWithRequest(): void
    {
        $request = new ServerRequest();
        $request = $request->withHeader('X-pass', 'request header');

        $app = new MiddlewareApplication($this->config);
        $server = new Server($app);
        $res = $server->run($request);
        $this->assertSame(
            'source header',
            $res->getHeaderLine('X-testing'),
            'Input response is carried through out middleware',
        );
        $this->assertSame(
            'request header',
            $res->getHeaderLine('X-pass'),
            'Request is used in middleware',
        );
    }

    /**
     * test run calling plugin hooks
     */
    public function testRunCallingPluginHooks(): void
    {
        $request = new ServerRequest();
        $request = $request->withHeader('X-pass', 'request header');

        /** @var \TestApp\Http\MiddlewareApplication|\Mockery\MockInterface $app */
        $app = Mockery::spy(MiddlewareApplication::class, [$this->config])
            ->makePartial();

        $server = new Server($app);
        $res = $server->run($request);
        $this->assertSame(
            'source header',
            $res->getHeaderLine('X-testing'),
            'Input response is carried through out middleware',
        );
        $this->assertSame(
            'request header',
            $res->getHeaderLine('X-pass'),
            'Request is used in middleware',
        );

        $app->shouldHaveReceived('pluginBootstrap')
            ->once();
        $app->shouldHaveReceived('pluginMiddleware')
            ->with(Mockery::type(MiddlewareQueue::class))
            ->once();
    }

    /**
     * test run building a request from globals.
     */
    public function testRunWithGlobals(): void
    {
        $_SERVER['HTTP_X_PASS'] = 'globalvalue';

        $app = new MiddlewareApplication($this->config);
        $server = new Server($app);

        $res = $server->run();
        $this->assertSame(
            'globalvalue',
            $res->getHeaderLine('X-pass'),
            'Default request is made from server',
        );
    }

    public function testRunBootstrapsEveryRequestByDefault(): void
    {
        $app = new class implements HttpApplicationInterface {
            public int $bootstraps = 0;

            public function bootstrap(): void
            {
                $this->bootstraps++;
            }

            public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
            {
                return $middlewareQueue;
            }

            public function handle(ServerRequestInterface $request): Response
            {
                return new Response();
            }
        };
        $server = new Server($app);

        $server->run(new ServerRequest());
        $server->run(new ServerRequest());

        $this->assertSame(2, $app->bootstraps);
    }

    public function testRunBootstrapsOnceInWorkerMode(): void
    {
        $app = new class implements HttpApplicationInterface {
            public int $bootstraps = 0;

            public function bootstrap(): void
            {
                $this->bootstraps++;
            }

            public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
            {
                return $middlewareQueue;
            }

            public function handle(ServerRequestInterface $request): Response
            {
                return new Response();
            }
        };
        $server = new Server($app);
        $server->setWorkerMode();

        try {
            $server->run(new ServerRequest());
            $server->run(new ServerRequest());

            $this->assertSame(1, $app->bootstraps);
        } finally {
            Router::setRouteCaching(false);
        }
    }

    /**
     * Test middleware being invoked.
     */
    public function testRunMultipleMiddlewareSuccess(): void
    {
        $app = new MiddlewareApplication($this->config);
        $server = new Server($app);
        $res = $server->run();
        $this->assertSame('first', $res->getHeaderLine('X-First'));
        $this->assertSame('second', $res->getHeaderLine('X-Second'));
    }

    /**
     * Test that run closes session after invoking the application (if CakePHP ServerRequest is used).
     */
    public function testRunClosesSessionIfServerRequestUsed(): void
    {
        $sessionMock = Mockery::mock(Session::class);
        $sessionMock->shouldReceive('close')
            ->once();

        $app = new MiddlewareApplication($this->config);
        $server = new Server($app);
        $request = new ServerRequest(['session' => $sessionMock]);
        $res = $server->run($request);

        // assert that app was executed correctly
        $this->assertSame(
            200,
            $res->getStatusCode(),
            'Application was expected to be executed',
        );
        $this->assertSame(
            'source header',
            $res->getHeaderLine('X-testing'),
            'Application was expected to be executed',
        );
    }

    /**
     * Test that run does not close the session if CakePHP ServerRequest is not used.
     */
    public function testRunDoesNotCloseSessionIfServerRequestNotUsed(): void
    {
        $request = new LaminasServerRequest();

        $app = new MiddlewareApplication($this->config);
        $server = new Server($app);
        $res = $server->run($request);

        // assert that app was executed correctly
        $this->assertSame(
            200,
            $res->getStatusCode(),
            'Application was expected to be executed',
        );
        $this->assertSame(
            'source header',
            $res->getHeaderLine('X-testing'),
            'Application was expected to be executed',
        );
    }

    /**
     * Test that emit invokes the appropriate methods on the emitter.
     */
    public function testEmit(): void
    {
        $app = new MiddlewareApplication($this->config);
        $server = new Server($app);
        $response = $server->run();
        $final = $response
            ->withHeader('X-First', 'first')
            ->withHeader('X-Second', 'second');

        $emitter = Mockery::mock(ResponseEmitter::class);
        $emitter->shouldReceive('emit')
            ->once()
            ->with($final);

        $server->emit($final, $emitter);
    }

    /**
     * Test that emit invokes the appropriate methods on the emitter.
     */
    public function testEmitCallbackStream(): void
    {
        $GLOBALS['mockedHeadersSent'] = false;
        $response = new LaminasResponse('php://memory', 200, ['x-testing' => 'source header']);
        $response = $response->withBody(new CallbackStream(function (): void {
            echo 'body content';
        }));

        $app = new MiddlewareApplication($this->config);
        $server = new Server($app);
        ob_start();
        $server->emit($response);
        $result = ob_get_clean();
        $this->assertSame('body content', $result);
    }

    /**
     * Ensure that the Server.buildMiddleware event is fired.
     */
    public function testBuildMiddlewareEvent(): void
    {
        $app = new MiddlewareApplication($this->config);
        $server = new Server($app);
        $called = false;

        $server->getEventManager()->on('Server.buildMiddleware', function (EventInterface $event, MiddlewareQueue $middlewareQueue) use (&$called): void {
            $middlewareQueue->add(function ($request, $handler) use (&$called) {
                $called = true;

                return $handler->handle($request);
            });
            $this->middlewareQueue = $middlewareQueue;
        });
        $server->run();
        $this->assertTrue($called, 'Middleware added in the event was not triggered.');
        $this->middlewareQueue->seek(3);
        $this->assertInstanceOf('Closure', $this->middlewareQueue->current()->getCallable(), '2nd last middleware is a closure');
    }

    /**
     * test event manager proxies to the application.
     */
    public function testEventManagerProxies(): void
    {
        $app = new class ($this->config) extends BaseApplication
        {
            public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
            {
                return $middlewareQueue;
            }
        };

        $server = new Server($app);
        $this->assertSame($app->getEventManager(), $server->getEventManager());
    }

    /**
     * test event manager cannot be set on applications without events.
     */
    public function testGetEventManagerNonEventedApplication(): void
    {
        $app = new class implements HttpApplicationInterface {
            public function bootstrap(): void
            {
            }

            public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
            {
                return $middlewareQueue;
            }

            public function handle(ServerRequestInterface $request): Response
            {
                return new Response();
            }
        };

        $server = new Server($app);
        $this->assertSame(EventManager::instance(), $server->getEventManager());
    }

    /**
     * test event manager cannot be set on applications without events.
     */
    public function testSetEventManagerNonEventedApplication(): void
    {
        $app = new class implements HttpApplicationInterface {
            public function bootstrap(): void
            {
            }

            public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
            {
                return $middlewareQueue;
            }

            public function handle(ServerRequestInterface $request): Response
            {
                return new Response();
            }
        };

        $events = new EventManager();
        $server = new Server($app);

        $this->expectException(InvalidArgumentException::class);

        $server->setEventManager($events);
    }

    /**
     * Test server run works without an application implementing ContainerApplicationInterface
     */
    public function testAppWithoutContainerApplicationInterface(): void
    {
        $app = new class implements HttpApplicationInterface {
            public function bootstrap(): void
            {
            }

            public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
            {
                return $middlewareQueue;
            }

            public function handle(ServerRequestInterface $request): Response
            {
                return new Response();
            }
        };
        $server = new Server($app);

        $request = new ServerRequest();
        $this->assertInstanceOf(ResponseInterface::class, $server->run($request));
    }

    public function testTerminateEvent(): void
    {
        $request = new ServerRequest();
        $app = new MiddlewareApplication($this->config);
        $app->getContainer()->add(ServerRequest::class, $request);
        $server = new Server($app);

        $triggered = false;
        $server->getEventManager()->on(
            'Server.terminate',
            function ($event, $request, $response) use (&$triggered): void {
                $triggered = true;
                $this->assertInstanceOf(ServerRequest::class, $request);
                $this->assertInstanceOf(Response::class, $response);
            },
        );

        $emitter = new class extends ResponseEmitter {
            public function emit(ResponseInterface $response, $stream = null): bool
            {
                return true;
            }
        };
        $server->emit(new Response(), $emitter);

        $this->assertTrue($triggered);
    }

    public function testResetWorkerState(): void
    {
        $originalLocale = I18n::getLocale();

        try {
            $request = new ServerRequest([
                'url' => '/articles/view/1',
                'params' => [
                    'controller' => 'Articles',
                    'action' => 'view',
                    'pass' => ['1'],
                ],
            ]);
            Router::setRequest($request);

            I18n::setLocale('fr_FR');

            $app = new MiddlewareApplication($this->config);
            $app->getContainer()->add(ServerRequest::class, $request);

            $server = new Server($app);
            $triggered = false;
            $server->getEventManager()->on(
                'Server.resetState',
                function (EventInterface $event) use (&$triggered): void {
                    $triggered = true;
                },
            );

            $server->resetWorkerState();

            $this->assertSame(I18n::getDefaultLocale(), I18n::getLocale());
            $this->assertNull(Router::getRequest());
            $this->assertFalse($app->getContainer()->has(ServerRequest::class));
            $this->assertTrue($triggered);
        } finally {
            I18n::setLocale($originalLocale);
            Router::reload();
        }
    }

    /**
     * Test that resetWorkerState() resets loaded cache engine worker state.
     */
    public function testResetWorkerStateResetsCacheWorkerState(): void
    {
        Cache::enable();
        Cache::setConfig('server_worker_array', [
            'engine' => 'Array',
            'prefix' => 'server_worker_array_',
        ]);

        try {
            $app = new MiddlewareApplication($this->config);
            $server = new Server($app);
            $server->run(new ServerRequest());

            Cache::write('key', 'value', 'server_worker_array');
            Cache::disable();

            $server->resetWorkerState();

            $this->assertTrue(Cache::enabled());
            $this->assertNull(Cache::read('key', 'server_worker_array'));
        } finally {
            Cache::enable();
            Cache::drop('server_worker_array');
        }
    }

    /**
     * Test that resetWorkerState() restores DateTime::$defaultLocale to its
     * post-bootstrap value when it is changed during a request.
     */
    public function testResetWorkerStateResetsDateTimeDefaultLocale(): void
    {
        $originalLocale = I18nDateTime::getDefaultLocale();

        try {
            $app = new MiddlewareApplication($this->config);
            $server = new Server($app);
            // Simulate bootstrap (captures initial state: null)
            $server->run(new ServerRequest());

            // Simulate request changing the locale
            I18nDateTime::setDefaultLocale('fr_FR');
            $this->assertSame('fr_FR', I18nDateTime::getDefaultLocale());

            $server->resetWorkerState();

            // Should be restored to the post-bootstrap value (null)
            $this->assertSame($originalLocale, I18nDateTime::getDefaultLocale());
        } finally {
            I18nDateTime::setDefaultLocale($originalLocale);
        }
    }

    /**
     * Test that resetWorkerState() restores DateTime::$defaultLocale to a
     * value explicitly set during bootstrap (not just null).
     */
    public function testResetWorkerStatePreservesBootstrapDateTimeLocale(): void
    {
        $originalLocale = I18nDateTime::getDefaultLocale();

        try {
            // Simulate an app that sets DateTime locale in bootstrap
            I18nDateTime::setDefaultLocale('de_DE');

            $app = new MiddlewareApplication($this->config);
            $server = new Server($app);
            // Run once to trigger bootstrap and capture 'de_DE' as the default
            $server->run(new ServerRequest());

            // Simulate request changing the locale to something else
            I18nDateTime::setDefaultLocale('ja_JP');
            $this->assertSame('ja_JP', I18nDateTime::getDefaultLocale());

            $server->resetWorkerState();

            // Should be restored to the bootstrap-time value 'de_DE', not null
            $this->assertSame('de_DE', I18nDateTime::getDefaultLocale());
        } finally {
            I18nDateTime::setDefaultLocale($originalLocale);
        }
    }

    /**
     * Test that resetWorkerState() restores Number currency defaults to their
     * post-bootstrap values when changed during a request.
     */
    public function testResetWorkerStateResetsNumberCurrencyDefaults(): void
    {
        $originalCurrency = Number::getDefaultCurrency();
        $originalFormat = Number::getDefaultCurrencyFormat();

        try {
            $app = new MiddlewareApplication($this->config);
            $server = new Server($app);
            // Simulate bootstrap
            $server->run(new ServerRequest());

            // Simulate request changing currency settings
            Number::setDefaultCurrency('EUR');
            Number::setDefaultCurrencyFormat(Number::FORMAT_CURRENCY_ACCOUNTING);
            $this->assertSame('EUR', Number::getDefaultCurrency());

            $server->resetWorkerState();

            // After reset the currency should be back to the post-bootstrap value
            $this->assertNotSame('EUR', Number::getDefaultCurrency());
        } finally {
            Number::setDefaultCurrency($originalCurrency);
            Number::setDefaultCurrencyFormat($originalFormat);
        }
    }

    /**
     * Test that resetWorkerState() preserves Number currency defaults that were
     * explicitly set during bootstrap.
     */
    public function testResetWorkerStatePreservesBootstrapCurrencyDefault(): void
    {
        $originalCurrency = Number::getDefaultCurrency();
        $originalFormat = Number::getDefaultCurrencyFormat();

        try {
            // Simulate an app that configures currency in bootstrap
            Number::setDefaultCurrency('GBP');
            Number::setDefaultCurrencyFormat(Number::FORMAT_CURRENCY_ACCOUNTING);

            $app = new MiddlewareApplication($this->config);
            $server = new Server($app);
            // Run once to trigger bootstrap and capture bootstrap defaults
            $server->run(new ServerRequest());

            // Simulate request changing currency to something else
            Number::setDefaultCurrency('JPY');
            $this->assertSame('JPY', Number::getDefaultCurrency());

            $server->resetWorkerState();

            // Should be restored to the bootstrap-time value 'GBP'
            $this->assertSame('GBP', Number::getDefaultCurrency());
            $this->assertSame(Number::FORMAT_CURRENCY_ACCOUNTING, Number::getDefaultCurrencyFormat());
        } finally {
            Number::setDefaultCurrency($originalCurrency);
            Number::setDefaultCurrencyFormat($originalFormat);
        }
    }
}
