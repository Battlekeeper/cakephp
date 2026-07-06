<?php
declare(strict_types=1);

/**
 * RoadRunner Worker Entry Point for CakePHP
 *
 * This script is executed by RoadRunner as a persistent PHP worker. It boots
 * the CakePHP application once and handles many requests in a loop, eliminating
 * per-request bootstrap overhead.
 *
 * @see https://roadrunner.dev/docs/php-worker
 */

// Check platform requirements
require dirname(__DIR__) . '/config/requirements.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Application;
use Cake\Http\Server;
use Cake\Http\ServerRequest;
use Cake\Http\ServerRequestFactory;
use Cake\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Spiral\RoadRunner;
use Spiral\RoadRunner\Http\PSR7Worker;

// Boot the application once; shared across all requests handled by this worker.
$server = new Server(new Application(dirname(__DIR__) . '/config'));
$server->setWorkerMode();

$psr17Factory = new Psr17Factory();
$rrWorker = new PSR7Worker(
    RoadRunner\Worker::create(),
    $psr17Factory,
    $psr17Factory,
    $psr17Factory,
);

$maxRequests = (int)($_SERVER['MAX_REQUESTS'] ?? 1);

for ($nbRequests = 0; !$maxRequests || $nbRequests < $maxRequests; ++$nbRequests) {
    try {
        $psr7Request = $rrWorker->waitRequest();
        if ($psr7Request === null) {
            break;
        }

        $request = withPsr7Headers(ServerRequestFactory::fromPsr7Request($psr7Request), $psr7Request);
        syncGlobalsFromRequest($request);
    } catch (Throwable $e) {
        error_log((string)$e);
        break;
    }

    try {
        $response = $server->run($request);
        $rrWorker->respond($response);

        $request = Router::getRequest() ?? $request;
        $server->dispatchEvent('Server.terminate', compact('request', 'response'));
    } catch (Throwable $e) {
        $rrWorker->getWorker()->error((string)$e);
        error_log((string)$e);
    } finally {
        $server->resetWorkerState();
        clearRequestGlobals();
        gc_collect_cycles();
    }
}

function withPsr7Headers(ServerRequest $request, ServerRequestInterface $psr7Request): ServerRequest
{
    foreach ($psr7Request->getHeaders() as $name => $values) {
        $request = $request->withHeader($name, $values);
    }

    return $request;
}

function syncGlobalsFromRequest(ServerRequest $request): void
{
    clearRequestGlobals();

    foreach ($request->getServerParams() as $key => $value) {
        $_SERVER[$key] = $value;
    }

    foreach ($request->getHeaders() as $name => $values) {
        $_SERVER[serverKeyFromHeader($name)] = implode(', ', $values);
    }

    $_COOKIE = $request->getCookieParams();
    $_GET = $request->getQueryParams();

    $parsedBody = $request->getParsedBody();
    $_POST = is_array($parsedBody) ? $parsedBody : [];
}

function clearRequestGlobals(): void
{
    clearSessionState();

    foreach (array_keys($_SERVER) as $key) {
        if (isRequestServerKey($key)) {
            unset($_SERVER[$key]);
        }
    }

    $_COOKIE = [];
    $_GET = [];
    $_POST = [];
}

function clearSessionState(): void
{
    $_SESSION = [];

    if (session_status() !== PHP_SESSION_ACTIVE && session_id() !== '' && !headers_sent()) {
        session_id('');
    }
}

function isRequestServerKey(string $key): bool
{
    return str_starts_with($key, 'HTTP_') ||
        str_starts_with($key, 'CONTENT_') ||
        in_array($key, [
            'REQUEST_METHOD',
            'REQUEST_URI',
            'QUERY_STRING',
            'SERVER_NAME',
            'SERVER_PORT',
            'SERVER_PROTOCOL',
            'SERVER_SOFTWARE',
            'GATEWAY_INTERFACE',
            'HTTPS',
            'REMOTE_ADDR',
            'REMOTE_PORT',
            'PHP_SELF',
            'SCRIPT_NAME',
            'SCRIPT_FILENAME',
            'DOCUMENT_ROOT',
        ], true);
}

function serverKeyFromHeader(string $name): string
{
    $key = strtoupper(str_replace('-', '_', $name));
    if (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
        return $key;
    }

    return 'HTTP_' . $key;
}
