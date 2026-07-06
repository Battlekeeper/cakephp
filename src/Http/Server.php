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
namespace Cake\Http;

use Cake\Cache\Cache;
use Cake\Core\ContainerApplicationInterface;
use Cake\Core\HttpApplicationInterface;
use Cake\Core\PluginApplicationInterface;
use Cake\Datasource\ConnectionManager;
use Cake\Error\Debug\HtmlFormatter;
use Cake\Event\EventDispatcherInterface;
use Cake\Event\EventDispatcherTrait;
use Cake\Event\EventManager;
use Cake\Event\EventManagerInterface;
use Cake\Http\Cookie\Cookie;
use Cake\I18n\Date as I18nDate;
use Cake\I18n\DateTime as I18nDateTime;
use Cake\I18n\I18n;
use Cake\I18n\Number;
use Cake\I18n\Time as I18nTime;
use Cake\ORM\TableRegistry;
use Cake\Routing\Router;
use Cake\Utility\Text;
use Cake\Validation\Validation;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Runs an application invoking all the PSR7 middleware and the registered application.
 *
 * @implements \Cake\Event\EventDispatcherInterface<\Cake\Core\HttpApplicationInterface>
 */
class Server implements EventDispatcherInterface
{
    /**
     * @use \Cake\Event\EventDispatcherTrait<\Cake\Core\HttpApplicationInterface>
     */
    use EventDispatcherTrait;

    /**
     * @var \Cake\Core\HttpApplicationInterface
     */
    protected HttpApplicationInterface $app;

    /**
     * Whether the application has already been bootstrapped.
     *
     * Used by worker mode to ensure bootstrap only runs once
     * per worker process regardless of how many requests are handled.
     *
     * @var bool
     */
    protected bool $bootstrapped = false;

    /**
     * Whether this server is running in a long-lived worker process.
     *
     * @var bool
     */
    protected bool $workerMode = false;

    /**
     * Whether superglobals were populated from a PSR-7 request for the current
     * request cycle. Used by resetWorkerState() to know whether to clear them.
     *
     * @var bool
     */
    protected bool $populatedSuperglobals = false;

    /**
     * Snapshot of request-scoped I18n defaults captured after application bootstrap.
     *
     * Keyed by the static property name; used by resetWorkerState() to restore
     * the post-bootstrap values between requests in worker mode.
     * Stored as instance state so that each Server instance (and each test)
     * has its own independent snapshot.
     *
     * @var array<string, mixed>
     */
    protected array $_workerI18nSnapshot = [];

    /**
     * Constructor
     *
     * @param \Cake\Core\HttpApplicationInterface $app The application to use.
     * @param \Cake\Http\Runner $runner Application runner.
     */
    public function __construct(HttpApplicationInterface $app, protected Runner $runner = new Runner())
    {
        $this->app = $app;
    }

    /**
     * Enable or disable long-lived worker mode.
     *
     * In worker mode application bootstrap and route registration are performed
     * once per Server instance. Request-scoped framework state can then be reset
     * between requests with resetWorkerState().
     *
     * @param bool $workerMode Whether worker mode should be enabled.
     * @return $this
     */
    public function setWorkerMode(bool $workerMode = true)
    {
        $this->workerMode = $workerMode;
        if (method_exists($this->app, 'setWorkerMode')) {
            $this->app->setWorkerMode($workerMode);
        }

        return $this;
    }

    /**
     * Run the request/response through the Application and its middleware.
     *
     * This will invoke the following methods:
     *
     * - App->bootstrap() - Perform any bootstrapping logic for your application here.
     * - App->middleware() - Attach any application middleware here.
     * - Trigger the 'Server.buildMiddleware' event. You can use this to modify the
     *   from event listeners.
     * - Run the middleware queue including the application.
     *
     * @param \Psr\Http\Message\ServerRequestInterface|null $request The request to use or null.
     * @param \Cake\Http\MiddlewareQueue|null $middlewareQueue MiddlewareQueue or null.
     * @return \Psr\Http\Message\ResponseInterface
     * @throws \RuntimeException When the application does not make a response.
     */
    public function run(
        ?ServerRequestInterface $request = null,
        ?MiddlewareQueue $middlewareQueue = null,
    ): ResponseInterface {
        $this->bootstrap();

        $request = $request ?: ServerRequestFactory::fromGlobals();
        if (!($request instanceof ServerRequest)) {
            $request = ServerRequestFactory::fromPsr7Request($request);
            $this->populateRequestSuperglobals($request);
        }

        if ($middlewareQueue === null) {
            if ($this->app instanceof ContainerApplicationInterface) {
                $middlewareQueue = new MiddlewareQueue([], $this->app->getContainer());
            } else {
                $middlewareQueue = new MiddlewareQueue();
            }
        }

        $middleware = $this->app->middleware($middlewareQueue);
        if ($this->app instanceof PluginApplicationInterface) {
            $middleware = $this->app->pluginMiddleware($middleware);
        }

        $this->dispatchEvent('Server.buildMiddleware', ['middleware' => $middleware]);

        $response = $this->runner->run($middleware, $request, $this->app);

        if ($this->workerMode && $request instanceof ServerRequest) {
            // Promote cookies stored in Response::$_cookies (CookieCollection)
            // into PSR-7 Set-Cookie headers. In PHP-FPM mode the ResponseEmitter
            // handles these via setcookie(); in worker mode the application
            // server (e.g. RoadRunner) reads only PSR-7 headers via getHeaders(),
            // so any cookie set through $response->withCookie() would be silently
            // dropped without this step.
            $response = $this->serializeResponseCookies($response);

            // Add the session cookie before the session is closed so that
            // session_id() is still valid when we read it.
            $response = $this->addSessionCookie($request, $response);
        }

        if ($request instanceof ServerRequest) {
            $request->getSession()->close();
        }

        return $response;
    }

    /**
     * Application bootstrap wrapper.
     *
     * Calls the application's `bootstrap()` hook. After the application the
     * plugins are bootstrapped.
     *
     * This method is idempotent: when running in worker mode the
     * same `Server` instance handles many requests, so bootstrap must only
     * execute once. Subsequent calls are silently skipped.
     *
     * @return void
     */
    protected function bootstrap(): void
    {
        if ($this->workerMode && $this->bootstrapped) {
            return;
        }
        $this->bootstrapped = true;
        Router::setRouteCaching($this->workerMode);
        $this->app->bootstrap();
        if ($this->app instanceof PluginApplicationInterface) {
            $this->app->pluginBootstrap();
        }
        // Snapshot request-scoped I18n defaults after bootstrap so that
        // resetWorkerState() can restore the post-bootstrap values between
        // requests in worker mode. Raw getters are used to avoid
        // triggering lazy-initialisation side-effects.
        if (class_exists(I18nDateTime::class, false)) {
            $this->_workerI18nSnapshot['dateTimeDefaultLocale'] = I18nDateTime::getDefaultLocale();
        }
        if (class_exists(Number::class, false)) {
            $this->_workerI18nSnapshot['numberDefaultCurrency'] = Number::getRawDefaultCurrency();
            $this->_workerI18nSnapshot['numberDefaultCurrencyFormat'] = Number::getRawDefaultCurrencyFormat();
        }
        // Snapshot Cache enabled state so it can be restored if disabled mid-request.
        if (class_exists(Cache::class, false)) {
            $this->_workerI18nSnapshot['cacheEnabled'] = Cache::enabled();
        }
        // Snapshot DateTime/Date/Time format settings so that per-request changes
        // (e.g. setToStringFormat(), setJsonEncodeFormat(), niceFormat mutations)
        // can be rolled back between requests in worker mode.
        if (class_exists(I18nDateTime::class, false)) {
            I18nDateTime::captureWorkerSnapshot();
        }
        if (class_exists(I18nDate::class, false)) {
            I18nDate::captureWorkerSnapshot();
        }
        if (class_exists(I18nTime::class, false)) {
            I18nTime::captureWorkerSnapshot();
        }
        // Snapshot Http static state (request detectors, cookie defaults, MIME types)
        // so that changes made during bootstrap persist across requests while
        // changes made during request handling are rolled back between requests.
        ServerRequest::captureWorkerSnapshot();
        Cookie::captureWorkerSnapshot();
        MimeType::captureWorkerSnapshot();
        // Snapshot Text transliterator settings set during bootstrap so that
        // per-request changes (e.g. locale-specific transliterators) can be
        // rolled back between requests in worker mode.
        if (class_exists(Text::class, false)) {
            Text::captureWorkerSnapshot();
        }
    }

    /**
     * Emit the response using the PHP SAPI.
     *
     * After the response has been emitted, the `Server.terminate` event will be triggered.
     *
     * The `Server.terminate` event can be used to do potentially heavy tasks after the
     * response is sent to the client. Only the PHP FPM server API is able to send a
     * response to the client while the server's PHP process still performs some tasks.
     * For other environments the event will be triggered before the response is flushed
     * to the client and will have no benefit.
     *
     * @param \Psr\Http\Message\ResponseInterface $response The response to emit
     * @param \Cake\Http\ResponseEmitter|null $emitter The emitter to use.
     *   When null, a SAPI Stream Emitter will be used.
     * @return void
     */
    public function emit(ResponseInterface $response, ?ResponseEmitter $emitter = null): void
    {
        $emitter ??= new ResponseEmitter();
        $emitter->emit($response);

        $request = null;
        if ($this->app instanceof ContainerApplicationInterface) {
            $container = $this->app->getContainer();
            if ($container->has(ServerRequest::class)) {
                $request = $container->get(ServerRequest::class);
            }
        }
        if (!$request) {
            $request = Router::getRequest();
        }
        $this->dispatchEvent('Server.terminate', compact('request', 'response'));
    }

    /**
     * Reset request-scoped framework state after a worker request completes.
     *
     * In long-running environments such as worker mode the same PHP
     * process handles many requests. State mutated during one request must be
     * restored before the next request begins to prevent cross-request leaks.
     *
     * This method resets the known framework-level state:
     *
     * - **I18n locale** - applications frequently change the active locale
     *   per-request (e.g. via `LocaleSelectorMiddleware`). The locale is
     *   restored to the value that was current when the worker first booted.
     * - **DateTime default locale** - if `DateTime::setDefaultLocale()` is
     *   called during request handling the value is restored to the
     *   post-bootstrap default (null by default, meaning "use IntlDateFormatter's
     *   own default").
     * - **Number default currency / format** - if `Number::setDefaultCurrency()`
     *   or `Number::setDefaultCurrencyFormat()` is called during a request the
     *   values are restored to their post-bootstrap defaults.
     * - **Router request context** - the stale `ServerRequest` reference and
     *   request-specific routing parameters are cleared.
     * - **Cache worker state** - the cache enabled flag is restored and loaded
     *   engines release request-scoped in-memory state.
     * - **Container request binding** - the request object registered in the
     *   application container is removed when the container supports removal.
     *
     * It also fires the `Server.resetState` event, giving applications and
     * plugins the opportunity to reset their own request-scoped state:
     *
     * ```php
     * // In your Application::bootstrap() or a plugin boot method:
     * EventManager::instance()->on('Server.resetState', function () {
     *     MyService::resetForNextRequest();
     * });
     * ```
     *
     * Call this method from your worker script's `finally` block:
     *
     * ```php
     * $handler = static function () use (\$server): void {
     *     try {
     *         \$server->emit(\$server->run());
     *     } finally {
     *         \$server->resetWorkerState();
     *     }
     * };
     * ```
     *
     * @return void
     */
    public function resetWorkerState(): void
    {
        // Clear superglobals that were populated from the PSR-7 request so
        // that one request's cookies, query params, and server data cannot
        // bleed into the next request.  This is especially important for
        // $_COOKIE: leaving it populated would allow the next request to
        // accidentally resume the previous user's session.
        if ($this->populatedSuperglobals) {
            $_SERVER = [];
            $_COOKIE = [];
            $_GET = [];
            $_POST = [];
            $this->populatedSuperglobals = false;
        }

        // Restore the I18n locale to whatever it was when the application
        // first booted. I18n::getDefaultLocale() captures the initial value
        // lazily on first call and never changes it, so this always restores
        // to the pre-request locale regardless of what setLocale() was called
        // with during the request.
        if (class_exists(I18n::class, false)) {
            I18n::setLocale(I18n::getDefaultLocale());
        }

        // Clear the stale request reference so that any code running after
        // the response has been sent (e.g. terminate-event handlers) cannot
        // accidentally read data from the previous request.
        Router::clearRequest();

        // Restore I18n DateTime and Number defaults to their post-bootstrap
        // values so that formatting settings changed during one request cannot
        // leak into the next. Falls back to null (the class default) when
        // these classes were loaded after bootstrap.
        if (class_exists(I18nDateTime::class, false)) {
            I18nDateTime::setDefaultLocale($this->_workerI18nSnapshot['dateTimeDefaultLocale'] ?? null);
            I18nDateTime::resetWorkerState();
        }
        if (class_exists(I18nDate::class, false)) {
            I18nDate::resetWorkerState();
        }
        if (class_exists(I18nTime::class, false)) {
            I18nTime::resetWorkerState();
        }
        if (class_exists(Number::class, false)) {
            Number::setDefaultCurrency($this->_workerI18nSnapshot['numberDefaultCurrency'] ?? null);
            Number::setDefaultCurrencyFormat($this->_workerI18nSnapshot['numberDefaultCurrencyFormat'] ?? null);
        }

        // Restore Cache enabled state to whatever it was after bootstrap.
        // Prevents a request that calls Cache::disable() from affecting the next request.
        if (class_exists(Cache::class, false)) {
            $cacheEnabled = $this->_workerI18nSnapshot['cacheEnabled'] ?? true;
            if ($cacheEnabled) {
                Cache::enable();
            } else {
                Cache::disable();
            }
            Cache::resetWorkerState();
        }

        // Reset the HTML debug formatter header flag so that each request that
        // contains debug output emits the required CSS and JavaScript.
        if (class_exists(HtmlFormatter::class, false)) {
            HtmlFormatter::reset();
        }

        // Roll back any open database transactions left over from the previous
        // request and reset connection state so each request starts clean.
        if (class_exists(ConnectionManager::class, false)) {
            ConnectionManager::resetWorkerState();
        }

        // Reset per-request ORM state: iterate all loaded Table instances and
        // invoke resetWorkerState() on each so that behaviors (e.g. a locale
        // override set via TranslateBehavior::setLocale()) are rolled back
        // before the next request starts.
        if (class_exists(TableRegistry::class, false)) {
            TableRegistry::getTableLocator()->resetWorkerState();
        }

        // Reset Http static state (request detectors, cookie defaults, MIME types)
        // to the post-bootstrap snapshot so that per-request mutations cannot
        // bleed into subsequent requests.
        ServerRequest::resetWorkerState();
        Cookie::resetWorkerState();
        MimeType::resetWorkerState();
        // Clear validation debug errors accumulated during the previous request.
        if (class_exists(Validation::class, false)) {
            Validation::resetWorkerState();
        }
        // Restore Text transliterator settings to their post-bootstrap values.
        if (class_exists(Text::class, false)) {
            Text::resetWorkerState();
        }

        if ($this->app instanceof ContainerApplicationInterface) {
            $container = $this->app->getContainer();
            if (method_exists($container, 'remove')) {
                $container->remove(ServerRequest::class);
            }
        }

        // Allow applications and plugins to reset their own request-scoped
        // state. Listeners should be registered in bootstrap(), not per-request.
        $this->dispatchEvent('Server.resetState');
    }

    /**
     * Get the current application.
     *
     * @return \Cake\Core\HttpApplicationInterface The application that will be run.
     */
    public function getApp(): HttpApplicationInterface
    {
        return $this->app;
    }

    /**
     * Populate PHP superglobals from a CakePHP ServerRequest.
     *
     * In long-lived worker processes (RoadRunner, Swoole, etc.) PHP never
     * receives a new SAPI request so superglobals are never re-populated by
     * the runtime. Any code — framework, library, or application — that reads
     * $_SERVER, $_COOKIE, $_GET, or $_POST directly will otherwise see stale
     * data from the previous request (or the worker boot environment).
     *
     * Populating $_COOKIE is especially critical: PHP's built-in session
     * engine reads the session ID from $_COOKIE[session_name()] inside
     * session_start(). If $_COOKIE is empty every request gets a brand-new
     * session, breaking authentication and flash messages.
     *
     * This method is called automatically by run() whenever it converts an
     * incoming PSR-7 ServerRequestInterface into a Cake\Http\ServerRequest.
     * resetWorkerState() clears these superglobals after each request cycle
     * to ensure one request's data cannot leak into the next.
     *
     * @param \Cake\Http\ServerRequest $request The fully-built CakePHP request.
     * @return void
     */
    protected function populateRequestSuperglobals(ServerRequest $request): void
    {
        $_SERVER = $request->getServerParams();
        $_COOKIE = $request->getCookieParams();
        $_GET = $request->getQueryParams();
        $parsedBody = $request->getParsedBody();
        $_POST = is_array($parsedBody) ? $parsedBody : [];
        $this->populatedSuperglobals = true;
    }

    /**
     * Promote cookies from the CakePHP CookieCollection into PSR-7 Set-Cookie headers.
     *
     * Cake\Http\Response stores cookies added via withCookie() in a separate
     * CookieCollection property that is invisible to PSR-7 header accessors
     * such as getHeaders() and getHeader('Set-Cookie'). Application servers
     * (e.g. RoadRunner, Swoole) read only the PSR-7 header store, so without
     * this step every cookie set by middleware through $response->withCookie()
     * — including CSRF tokens, remember-me cookies, and flash indicators —
     * would be silently dropped before reaching the browser.
     *
     * In the normal PHP-FPM flow this is handled by ResponseEmitter which
     * calls setcookie() for each cookie in the collection, so this method
     * is only invoked in worker mode.
     *
     * @param \Psr\Http\Message\ResponseInterface $response The response to enrich.
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function serializeResponseCookies(ResponseInterface $response): ResponseInterface
    {
        if (!($response instanceof Response)) {
            return $response;
        }

        foreach ($response->getCookieCollection() as $cookie) {
            $response = $response->withAddedHeader('Set-Cookie', $cookie->toHeaderValue());
        }

        return $response;
    }

    /**
     * Add the session cookie to the response when running in worker mode.
     *
     * In CLI-based worker processes (RoadRunner, Swoole, etc.) PHP's SAPI
     * never handles HTTP headers, so even when session_start() is called it
     * does not queue a Set-Cookie header for PHPSESSID. This method replicates
     * that header explicitly using the same session.cookie_* ini settings PHP
     * itself reads in FPM mode.
     *
     * The cookie is only emitted when:
     * - The session was actually started during this request.
     * - The session ID differs from the one the client sent (new session or
     *   after session_regenerate_id()), matching PHP-FPM behaviour where the
     *   header is only written for new or regenerated sessions.
     *
     * Must be called before Session::close() so that session_id() is valid.
     *
     * @param \Cake\Http\ServerRequest $request The current request.
     * @param \Psr\Http\Message\ResponseInterface $response The response to add the cookie to.
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function addSessionCookie(ServerRequest $request, ResponseInterface $response): ResponseInterface
    {
        if (!$request->getSession()->started()) {
            return $response;
        }

        $sessionId = session_id();
        if ($sessionId === '') {
            return $response;
        }

        $sessionName = session_name();

        // Don't re-send the cookie when the client already holds this session
        // ID (matches PHP-FPM behaviour: the header is only emitted for new
        // sessions or after session_regenerate_id()).
        $incomingId = $request->getCookieParams()[$sessionName] ?? null;
        if ($incomingId === $sessionId) {
            return $response;
        }

        // Build the Set-Cookie value mirroring PHP's own session cookie output.
        $cookieValue = urlencode($sessionName) . '=' . urlencode($sessionId);

        $lifetime = (int)ini_get('session.cookie_lifetime');
        if ($lifetime > 0) {
            $cookieValue .= '; expires=' . gmdate('D, d-M-Y H:i:s', time() + $lifetime) . ' GMT';
            $cookieValue .= '; Max-Age=' . $lifetime;
        }

        $path = (string)ini_get('session.cookie_path');
        if ($path !== '') {
            $cookieValue .= '; path=' . $path;
        }

        $domain = (string)ini_get('session.cookie_domain');
        if ($domain !== '') {
            $cookieValue .= '; domain=' . $domain;
        }

        if (filter_var(ini_get('session.cookie_secure'), FILTER_VALIDATE_BOOLEAN)) {
            $cookieValue .= '; secure';
        }

        if (filter_var(ini_get('session.cookie_httponly'), FILTER_VALIDATE_BOOLEAN)) {
            $cookieValue .= '; httponly';
        }

        $sameSite = (string)ini_get('session.cookie_samesite');
        if ($sameSite !== '') {
            $cookieValue .= '; SameSite=' . $sameSite;
        }

        return $response->withAddedHeader('Set-Cookie', $cookieValue);
    }

    /**
     * Get the application's event manager or the global one.
     *
     * @return \Cake\Event\EventManagerInterface
     */
    public function getEventManager(): EventManagerInterface
    {
        if ($this->app instanceof EventDispatcherInterface) {
            return $this->app->getEventManager();
        }

        return EventManager::instance();
    }

    /**
     * Set the application's event manager.
     *
     * If the application does not support events, an exception will be raised.
     *
     * @param \Cake\Event\EventManagerInterface $eventManager The event manager to set.
     * @return $this
     * @throws \InvalidArgumentException
     */
    public function setEventManager(EventManagerInterface $eventManager)
    {
        if ($this->app instanceof EventDispatcherInterface) {
            $this->app->setEventManager($eventManager);

            return $this;
        }

        throw new InvalidArgumentException('Cannot set the event manager, the application does not support events.');
    }
}
