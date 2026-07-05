<?php
declare(strict_types=1);

/**
 * FrankenPHP Worker Entry Point for CakePHP
 *
 * This file is intended to replace (or be referenced by) your application's
 * `webroot/index.php` when using FrankenPHP's worker mode. It boots the
 * CakePHP application **once** and then reuses it across many requests,
 * delivering significantly better throughput than classic PHP-FPM.
 *
 * It also falls back gracefully to the standard single-request mode when
 * FrankenPHP is not in use (e.g. plain PHP-FPM, CLI, or local dev).
 *
 * ## Quick start (standalone binary)
 *
 *   frankenphp php-server --worker webroot/index.php
 *
 * ## Docker
 *
 *   docker run \
 *       -e FRANKENPHP_CONFIG="worker /app/webroot/index.php" \
 *       -v $PWD:/app \
 *       -p 80:80 -p 443:443 -p 443:443/udp \
 *       dunglas/frankenphp
 *
 * ## Usage
 *
 * 1. Copy this file to `webroot/index.php` in your CakePHP application,
 *    replacing the default index.php.
 * 2. Optionally configure a Caddyfile (see `../frankenphp/Caddyfile`).
 * 3. Start FrankenPHP pointing at your webroot.
 *
 * ## Per-worker request limit
 *
 * Set the `MAX_REQUESTS` environment variable to automatically restart a
 * worker after N requests. This is a pragmatic workaround for third-party
 * libraries that leak memory:
 *
 *   FRANKENPHP_CONFIG="worker /app/webroot/index.php" MAX_REQUESTS=500 frankenphp run
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
 * or services do hold request-specific state, reset it by listening to the
 * `Server.resetState` event that this script triggers after each request.
 *
 * @see https://frankenphp.dev/docs/worker/
 */

use App\Application;
use Cake\Http\Server;

// Load Composer's autoloader.
require dirname(__DIR__) . '/vendor/autoload.php';

// Create the application and HTTP server.
// These objects are shared across all requests handled by this worker.
$server = new Server(new Application(dirname(__DIR__) . '/config'));

if (function_exists('frankenphp_handle_request')) {
    $server->setWorkerMode();

    // ---------------------------------------------------------------------------
    // FrankenPHP worker mode
    //
    // The application is booted on the first call to $server->run() and kept
    // alive in memory. Subsequent calls skip bootstrapping entirely thanks to
    // the idempotent guard added to Server::bootstrap().
    //
    // Routes are loaded on the first request and reused for all subsequent
    // requests thanks to the idempotent guard in RoutingMiddleware::loadRoutes().
    // ---------------------------------------------------------------------------

    $maxRequests = (int)($_SERVER['MAX_REQUESTS'] ?? 0);

    $handler = static function () use ($server): void {
        try {
            // ServerRequestFactory::fromGlobals() reads the superglobals that
            // FrankenPHP has already populated for the current request.
            $server->emit($server->run());
        } catch (Throwable $e) {
            // Prevent the worker from crashing on unhandled exceptions.
            // CakePHP's ErrorHandlerMiddleware should catch most of these first,
            // but this acts as a last-resort safety net.
            http_response_code(500);
            echo 'An Internal Server Error Occurred';
            error_log((string)$e);
        } finally {
            // Reset request-scoped framework state (I18n locale, Router request
            // context) and fire the Server.resetState event so application code
            // can reset its own per-request state.
            //
            // This runs even when an exception was thrown so that a failed
            // request cannot corrupt the state seen by the next request.
            //
            // To reset your own services, listen to 'Server.resetState' in
            // your Application::bootstrap():
            //
            //   EventManager::instance()->on('Server.resetState', function () {
            //       MyService::reset();
            //   });
            $server->resetWorkerState();
        }
    };

    for ($nbRequests = 0; !$maxRequests || $nbRequests < $maxRequests; ++$nbRequests) {
        $keepRunning = frankenphp_handle_request($handler);

        // Proactively collect reference cycles after each request to reduce the
        // chance of the garbage collector running mid-request on the next iteration.
        gc_collect_cycles();

        if (!$keepRunning) {
            break;
        }
    }
} else {
    // ---------------------------------------------------------------------------
    // Classic mode (PHP-FPM, CGI, PHP built-in server, etc.)
    //
    // Behaviour is identical to the default CakePHP webroot/index.php.
    // ---------------------------------------------------------------------------
    $server->emit($server->run());
}
