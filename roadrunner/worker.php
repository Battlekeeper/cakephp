<?php
declare(strict_types=1);

/**
 * RoadRunner Worker Entry Point for CakePHP
 *
 * This file is the PHP worker script executed by RoadRunner. It boots the
 * CakePHP application **once** and then handles many requests inside a tight
 * loop, communicating with the RoadRunner Go server over pipes. This delivers
 * significantly better throughput than classic PHP-FPM by eliminating the
 * per-request bootstrap overhead.
 *
 * ## Requirements
 *
 * Install the RoadRunner HTTP worker package in your application:
 *
 *   composer require spiral/roadrunner-http nyholm/psr7
 *
 * Download the RoadRunner binary:
 *
 *   ./vendor/bin/rr get-binary
 *
 * ## Quick start
 *
 *   ./rr serve -c roadrunner/.rr.yaml
 *
 * ## Docker
 *
 *   FROM ghcr.io/roadrunner-server/roadrunner:latest AS roadrunner
 *   FROM php:8.2-cli
 *   COPY --from=roadrunner /usr/bin/rr /usr/bin/rr
 *   COPY . /app
 *   WORKDIR /app
 *   CMD ["/usr/bin/rr", "serve", "-c", "roadrunner/.rr.yaml"]
 *
 * ## Usage
 *
 * 1. Copy this file to `roadrunner/worker.php` in your CakePHP application
 *    (or adjust the `server.command` in `.rr.yaml` to point to this file).
 * 2. Copy and adjust `roadrunner/.rr.yaml` to match your project layout.
 * 3. Run `./rr serve -c roadrunner/.rr.yaml`.
 *
 * ## Per-worker request limit
 *
 * Set the `MAX_REQUESTS` environment variable (or the `pool.max_jobs` setting
 * in `.rr.yaml`) to automatically restart a worker after N requests. This is
 * a pragmatic workaround for third-party libraries that accumulate memory:
 *
 *   # In .rr.yaml:
 *   http:
 *     pool:
 *       max_jobs: 500
 *
 *   # Or via environment variable passed to the pool:
 *   server:
 *     env:
 *       MAX_REQUESTS: 500
 *
 * ## Persistent process state
 *
 * Worker mode keeps the PHP process alive between requests. This is what makes
 * worker mode fast, but it also means process-level state persists across
 * requests, including:
 *
 * - Static variables declared inside functions or methods.
 * - Class static properties.
 * - Global variables in this worker script.
 * - In-memory caches stored outside the request handler.
 *
 * Avoid storing request-specific data in persistent state. If your application
 * or a service does hold request-specific state, reset it by listening to the
 * `Server.resetState` event that this script triggers after each request:
 *
 *   // In your Application::bootstrap():
 *   EventManager::instance()->on('Server.resetState', function () {
 *       MyService::reset();
 *   });
 *
 * @see https://roadrunner.dev/docs/php-worker
 * @see https://github.com/roadrunner-server/roadrunner
 */

use App\Application;
use Cake\Http\Server;
use Cake\Routing\Router;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use Laminas\Diactoros\UploadedFileFactory;
use Spiral\RoadRunner;
use Spiral\RoadRunner\Http\PSR7Worker;

// Load Composer's autoloader.
require dirname(__DIR__) . '/vendor/autoload.php';

// Create the application and HTTP server.
// These objects are shared across all requests handled by this worker.
$server = new Server(new Application(dirname(__DIR__) . '/config'));
$server->setWorkerMode();

// ---------------------------------------------------------------------------
// RoadRunner worker setup
//
// PSR7Worker converts the RoadRunner binary protocol into PSR-7 objects.
// Laminas Diactoros factories are used here because CakePHP already depends
// on laminas/laminas-diactoros. If you have installed nyholm/psr7 you may
// substitute Nyholm\Psr7\Factory\Psr17Factory for all three factories.
// ---------------------------------------------------------------------------
$rrWorker = new PSR7Worker(
    RoadRunner\Worker::create(),
    new ServerRequestFactory(),
    new StreamFactory(),
    new UploadedFileFactory(),
);

$maxRequests = (int)($_SERVER['MAX_REQUESTS'] ?? 0);

for ($nbRequests = 0; !$maxRequests || $nbRequests < $maxRequests; ++$nbRequests) {
    try {
        $request = $rrWorker->waitRequest();
    } catch (Throwable $e) {
        // The RoadRunner server sent a malformed request or closed the pipe.
        // Log and exit; RoadRunner will restart this worker automatically.
        error_log((string)$e);
        break;
    }

    if ($request === null) {
        // RoadRunner sent a graceful stop signal.
        break;
    }

    try {
        // ---------------------------------------------------------------------------
        // CakePHP request handling
        //
        // Server::run() bootstraps the application on the first call and is
        // idempotent for all subsequent calls in worker mode. Routes are compiled
        // on the first request and reused for all subsequent requests.
        //
        // The PSR-7 ServerRequest from RoadRunner is passed directly to run() so
        // that CakePHP processes the RoadRunner-provided headers, body, and
        // uploaded files rather than reading PHP superglobals.
        // ---------------------------------------------------------------------------
        $response = $server->run($request);

        // Send the PSR-7 response back to RoadRunner over the pipe.
        // RoadRunner will forward it to the client as an HTTP response.
        $rrWorker->respond($response);

        // Dispatch Server.terminate after the response has been handed off to
        // RoadRunner. Listeners may perform deferred work (e.g. queue jobs,
        // send analytics pings) that should not delay the client response.
        $request = Router::getRequest() ?? $request;
        $server->dispatchEvent('Server.terminate', compact('request', 'response'));
    } catch (Throwable $e) {
        // Prevent the worker from crashing on unhandled exceptions.
        // CakePHP's ErrorHandlerMiddleware should catch most of these first,
        // but this acts as a last-resort safety net.
        $rrWorker->getWorker()->error((string)$e);
        error_log((string)$e);
    } finally {
        // Reset request-scoped framework state (I18n locale, Router request
        // context) and fire the Server.resetState event so application code
        // can reset its own per-request state.
        //
        // This runs even when an exception was thrown so that a failed
        // request cannot corrupt the state seen by the next request.
        $server->resetWorkerState();

        // Proactively collect reference cycles after each request to reduce the
        // chance of the garbage collector running mid-request on the next iteration.
        gc_collect_cycles();
    }
}
