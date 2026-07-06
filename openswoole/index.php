<?php
declare(strict_types=1);

/**
 * OpenSwoole Worker Entry Point for CakePHP
 *
 * This file starts an OpenSwoole HTTP server that boots the CakePHP application
 * **once per worker process** and reuses it across many requests, delivering
 * significantly better throughput than classic PHP-FPM.
 *
 * ## Requirements
 *
 *   composer require openswoole/core
 *
 * ## Quick start
 *
 *   php openswoole/index.php
 *
 * ## Docker
 *
 *   docker run \
 *       -v $PWD:/app -w /app \
 *       -p 9501:9501 \
 *       openswoole/swoole \
 *       php openswoole/index.php
 *
 * ## Environment variables
 *
 * | Variable      | Default           | Description                               |
 * |---------------|-------------------|-------------------------------------------|
 * | HOST          | 0.0.0.0           | IP address to listen on.                  |
 * | PORT          | 9501              | TCP port to listen on.                    |
 * | WORKER_NUM    | CPU count         | Number of worker processes to spawn.      |
 * | MAX_REQUESTS  | 0 (unlimited)     | Restart a worker after N requests.        |
 *
 * ## Persistent process state
 *
 * OpenSwoole worker processes are long-lived; state mutated during one request
 * persists into the next unless explicitly reset. CakePHP's worker mode handles
 * the built-in framework state (I18n locale, Router request context, Cache, ORM,
 * static format settings, etc.) automatically via `resetWorkerState()`.
 *
 * To reset your own services, listen to 'Server.resetState' in your
 * Application::bootstrap():
 *
 *   EventManager::instance()->on('Server.resetState', function () {
 *       MyService::resetForNextRequest();
 *   });
 *
 * ## Sessions
 *
 * PHP's native file-based session handler is not safe to use in a long-running
 * process. Switch to a database or cache-backed session handler in your app:
 *
 *   // config/app.php
 *   'Session' => ['defaults' => 'cake'],   // uses CakePHP's CacheSession handler
 *
 * @see https://openswoole.com/docs/modules/swoole-http-server
 * @see https://book.cakephp.org/5/en/deployment.html
 */

use App\Application;
use Cake\Http\Cookie\CookieInterface;
use Cake\Http\Response as CakeResponse;
use Cake\Http\Server;
use Cake\Http\ServerRequestFactory;
use OpenSwoole\HTTP\Request as SwooleRequest;
use OpenSwoole\HTTP\Response as SwooleResponse;
use OpenSwoole\HTTP\Server as SwooleServer;
use Psr\Http\Message\ResponseInterface;
use Throwable;

require dirname(__DIR__) . '/vendor/autoload.php';

// ---------------------------------------------------------------------------
// OpenSwoole HTTP server setup
// ---------------------------------------------------------------------------

$host = (string)($_ENV['HOST'] ?? '0.0.0.0');
$port = (int)($_ENV['PORT'] ?? 9501);
$workerNum = (int)($_ENV['WORKER_NUM'] ?? OpenSwoole\Util::getCPUNum());
$maxRequests = (int)($_ENV['MAX_REQUESTS'] ?? 0);

$swServer = new SwooleServer($host, $port);

$swServer->set([
    // Each worker is an independent OS process that boots its own copy of the
    // CakePHP application, so all workers can safely share nothing.
    'worker_num' => $workerNum,

    // Automatically recycle a worker after N requests to recover any memory
    // that third-party libraries may have leaked. 0 means no limit.
    'max_request' => $maxRequests,

    // Enable coroutine support so that async I/O libraries (database clients,
    // HTTP clients, etc.) can yield while waiting for results.
    'enable_coroutine' => true,

    // Redirect OpenSwoole's own error output to stderr so it is captured by
    // your log aggregator alongside CakePHP's log output.
    'log_file' => '/dev/stderr',
]);

// ---------------------------------------------------------------------------
// Per-worker bootstrap
//
// 'workerStart' fires once in each worker process after it has been forked
// from the master. Creating the CakePHP Server here (rather than before
// start()) ensures that each worker owns its own isolated instance – no
// file handles or database connections are inherited from the master process.
// ---------------------------------------------------------------------------

/** @var \Cake\Http\Server|null $cakeServer */
$cakeServer = null;

$swServer->on('workerStart', function () use (&$cakeServer): void {
    $cakeServer = new Server(new Application(dirname(__DIR__) . '/config'));

    // Enable worker mode so that:
    //   • Application::bootstrap() runs exactly once per worker process.
    //   • Routes are compiled once and cached in memory across requests.
    //   • resetWorkerState() knows which snapshots to restore after each request.
    $cakeServer->setWorkerMode();
});

// ---------------------------------------------------------------------------
// Request handler
// ---------------------------------------------------------------------------

$swServer->on('request', function (SwooleRequest $swRequest, SwooleResponse $swResponse) use (&$cakeServer): void {
    assert($cakeServer !== null, 'CakePHP server was not initialised in workerStart');

    try {
        // Build a CakePHP ServerRequest from the OpenSwoole request object.
        // php://input is not populated in OpenSwoole workers, so the raw body
        // is forwarded via the CAKEPHP_INPUT server key instead.
        $server = buildServerVars($swRequest);

        $cakeRequest = ServerRequestFactory::fromGlobals(
            $server,
            $swRequest->get ?? [],
            $swRequest->post ?? [],
            $swRequest->cookie ?? [],
            $swRequest->files ?? [],
        );

        $response = $cakeServer->run($cakeRequest);

        sendResponse($response, $swResponse);
    } catch (Throwable $e) {
        // CakePHP's ErrorHandlerMiddleware catches most exceptions before they
        // reach here. This is a last-resort handler so the worker does not die.
        error_log((string)$e);

        try {
            $swResponse->status(500);
            $swResponse->end('An Internal Server Error Occurred');
        } catch (Throwable) {
            // Response may already be partially sent; nothing more we can do.
        }
    } finally {
        // Reset all request-scoped framework state (I18n locale, Router context,
        // Cache, ORM, format settings, …) and fire 'Server.resetState' so that
        // application code can reset its own per-request state as well.
        //
        // This runs even when an exception was thrown so that a failed request
        // cannot corrupt the state seen by the next request.
        $cakeServer->resetWorkerState();
    }
});

$swServer->start();

// ---------------------------------------------------------------------------
// Helper functions
// ---------------------------------------------------------------------------

/**
 * Build a $_SERVER-compatible array from an OpenSwoole HTTP request.
 *
 * OpenSwoole does not populate PHP superglobals, so we normalise its request
 * object into the format that ServerRequestFactory::fromGlobals() expects.
 *
 * @param \OpenSwoole\HTTP\Request $swRequest The incoming OpenSwoole request.
 * @return array<string, mixed>
 */
function buildServerVars(SwooleRequest $swRequest): array
{
    $sw = $swRequest->server ?? [];

    $server = [
        'REQUEST_METHOD'  => strtoupper($sw['request_method'] ?? 'GET'),
        'REQUEST_URI'     => $sw['request_uri'] ?? '/',
        'SERVER_PROTOCOL' => $sw['server_protocol'] ?? 'HTTP/1.1',
        'REMOTE_ADDR'     => $sw['remote_addr'] ?? '127.0.0.1',
        'SERVER_PORT'     => (string)($sw['server_port'] ?? 9501),
        'QUERY_STRING'    => $sw['query_string'] ?? '',
        'DOCUMENT_ROOT'   => dirname(__DIR__) . '/webroot',
        'SCRIPT_NAME'     => '/index.php',
        'PHP_SELF'        => '/index.php',
        'SERVER_NAME'     => 'localhost',
    ];

    // Map HTTP request headers to PHP's HTTP_* naming convention.
    foreach ($swRequest->header ?? [] as $name => $value) {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $server[$key] = $value;
    }

    // Content-Type and Content-Length must not carry the HTTP_ prefix so that
    // CakePHP's body parser and content-type negotiation work correctly.
    if (isset($swRequest->header['content-type'])) {
        $server['CONTENT_TYPE'] = $swRequest->header['content-type'];
        unset($server['HTTP_CONTENT_TYPE']);
    }
    if (isset($swRequest->header['content-length'])) {
        $server['CONTENT_LENGTH'] = $swRequest->header['content-length'];
        unset($server['HTTP_CONTENT_LENGTH']);
    }
    // Derive SERVER_NAME from the Host header when available.
    if (isset($swRequest->header['host'])) {
        $server['SERVER_NAME'] = explode(':', $swRequest->header['host'])[0];
    }

    // Forward the raw request body via CakePHP's CAKEPHP_INPUT key.
    // This is required because php://input is unavailable in OpenSwoole workers.
    $rawBody = $swRequest->rawContent();
    if ($rawBody !== false && $rawBody !== null && $rawBody !== '') {
        $server['CAKEPHP_INPUT'] = $rawBody;
    }

    return $server;
}

/**
 * Write a PSR-7 response to an OpenSwoole HTTP response.
 *
 * @param \Psr\Http\Message\ResponseInterface $response The CakePHP response.
 * @param \OpenSwoole\HTTP\Response $swResponse The OpenSwoole response handle.
 * @return void
 */
function sendResponse(ResponseInterface $response, SwooleResponse $swResponse): void
{
    $swResponse->status($response->getStatusCode(), $response->getReasonPhrase());

    // Collect cookies from CakePHP's cookie collection (set via withCookie()).
    // These are NOT reflected in the PSR-7 Set-Cookie header – they live in a
    // separate CookieCollection on Cake\Http\Response and would be silently
    // dropped if we only iterate getHeaders().
    $collectionCookies = [];
    if ($response instanceof CakeResponse) {
        $collectionCookies = iterator_to_array($response->getCookieCollection());
    }

    foreach ($response->getHeaders() as $name => $values) {
        // OpenSwoole's header() overwrites the previous value when the same
        // header name is set twice. For Set-Cookie (the only standard header
        // that legitimately carries multiple values as separate header lines)
        // use rawcookie() so that each cookie is sent in its own header line.
        if (strtolower($name) === 'set-cookie') {
            foreach ($values as $cookieLine) {
                emitRawCookieLine($cookieLine, $swResponse);
            }
        } else {
            $swResponse->header($name, implode(', ', $values));
        }
    }

    foreach ($collectionCookies as $cookie) {
        emitCookieObject($cookie, $swResponse);
    }

    $body = $response->getBody();
    $body->rewind();
    $swResponse->end($body->getContents());
}

/**
 * Emit a fully-formed Set-Cookie header string via OpenSwoole rawcookie().
 *
 * @param string $cookieLine A complete Set-Cookie header value.
 * @param \OpenSwoole\HTTP\Response $swResponse The OpenSwoole response handle.
 * @return void
 */
function emitRawCookieLine(string $cookieLine, SwooleResponse $swResponse): void
{
    // CakePHP emits fully-formed Set-Cookie header values, so we split
    // on semicolons to extract the name=value pair and attributes.
    $parts = array_map('trim', explode(';', $cookieLine));
    [$cookieName, $cookieValue] = array_pad(explode('=', array_shift($parts), 2), 2, '');

    $attrs = [];
    foreach ($parts as $part) {
        [$attrKey, $attrVal] = array_pad(explode('=', $part, 2), 2, '');
        $attrs[strtolower(trim($attrKey))] = trim($attrVal);
    }

    $swResponse->rawcookie(
        $cookieName,
        $cookieValue,
        isset($attrs['expires']) ? (int)strtotime($attrs['expires']) : 0,
        $attrs['path'] ?? '/',
        $attrs['domain'] ?? '',
        isset($attrs['secure']),
        isset($attrs['httponly']),
        $attrs['samesite'] ?? '',
    );
}

/**
 * Emit a CakePHP CookieInterface object via OpenSwoole rawcookie().
 *
 * @param \Cake\Http\Cookie\CookieInterface $cookie The cookie to emit.
 * @param \OpenSwoole\HTTP\Response $swResponse The OpenSwoole response handle.
 * @return void
 */
function emitCookieObject(CookieInterface $cookie, SwooleResponse $swResponse): void
{
    $swResponse->rawcookie(
        $cookie->getName(),
        $cookie->getScalarValue(),
        $cookie->getExpiresTimestamp() ?? 0,
        $cookie->getPath(),
        $cookie->getDomain(),
        $cookie->isSecure(),
        $cookie->isHttpOnly(),
        $cookie->getSameSite()?->value ?? '',
    );
}
