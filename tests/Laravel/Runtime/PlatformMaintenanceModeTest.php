<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Ske\Bridge\Laravel\Runtime\PlatformMaintenanceMode;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

const BYPASS_TOKEN = '6f1c0e9a4b7d2385f0a1c3e5b7d9f1a3c5e7092b4d6f8a1c3e5b7d9f1a3c5e70';

/**
 * Sends a request through Laravel's own PreventRequestsDuringMaintenance with
 * the platform's maintenance mode switched on, restoring the container after.
 * $token is the bypass token the adapter puts in the environment; null is an
 * adapter that sets none.
 *
 * @param  array<string, string>  $cookies
 * @param  array<string, string>  $server
 */
function duringMaintenance(?string $secret, string $path, array $cookies = [], array $server = [], ?string $token = BYPASS_TOKEN): Response
{
    $container = Container::getInstance();
    $response = null;

    withSkeEnvVariable('MAINTENANCE_MODE', function () use ($secret, $path, $cookies, $server, $token, &$response) {
        withSkeEnvVariable('MAINTENANCE_MODE_SECRET', function () use ($secret, $path, $cookies, $server, $token, &$response) {
            withSkeEnvVariable('SKE_MAINTENANCE_BYPASS_TOKEN', function () use ($secret, $path, $cookies, $server, $token, &$response) {
                $_SERVER['MAINTENANCE_MODE'] = 'true';

                if ($secret !== null) {
                    $_SERVER['MAINTENANCE_MODE_SECRET'] = $secret;
                }

                if ($token !== null) {
                    $_SERVER['SKE_MAINTENANCE_BYPASS_TOKEN'] = $token;
                }

                $request = Request::create($path, 'GET', cookies: $cookies, server: $server);
                $app = new Application(sys_get_temp_dir());
                $app->instance('request', $request);
                $app->instance('config', new Repository(['session' => ['path' => '/', 'domain' => null]]));
                $app->instance(MaintenanceMode::class, new PlatformMaintenanceMode);

                $redirector = new Redirector(new UrlGenerator(new RouteCollection, $request));
                $redirector->setSession(new Store('test', new ArraySessionHandler(1)));
                $app->instance('redirect', $redirector);

                try {
                    $response = (new PreventRequestsDuringMaintenance($app))
                        ->handle($request, fn (): Response => new Response('open'));
                } catch (HttpException $e) {
                    $response = new Response('closed', $e->getStatusCode());
                }
            });
        });
    });

    Container::setInstance($container);

    return $response;
}

it('is active only while the platform sets MAINTENANCE_MODE=true', function (?string $value, bool $active) {
    withSkeEnvVariable('MAINTENANCE_MODE', function () use ($value, $active) {
        if ($value !== null) {
            $_SERVER['MAINTENANCE_MODE'] = $value;
        }

        expect((new PlatformMaintenanceMode)->active())->toBe($active);
    });
})->with([
    'unset' => [null, false],
    'true' => ['true', true],
    'false' => ['false', false],
]);

it('carries no maintenance payload without a bypass secret', function () {
    withSkeEnvVariable('MAINTENANCE_MODE_SECRET', function () {
        expect((new PlatformMaintenanceMode)->data())->toBe([]);
    });
});

it('carries MAINTENANCE_MODE_SECRET as the bypass secret', function () {
    withSkeEnvVariable('MAINTENANCE_MODE_SECRET', function () {
        $_SERVER['MAINTENANCE_MODE_SECRET'] = 'let-me-in';

        expect((new PlatformMaintenanceMode)->data())->toBe(['secret' => 'let-me-in']);
    });
});

it('closes the application to everyone without a bypass secret', function () {
    expect(duringMaintenance(null, '/let-me-in')->getStatusCode())->toBe(503);
});

it('lets a visitor of /{secret} through with a bypass cookie', function () {
    $response = duringMaintenance('let-me-in', '/let-me-in');
    $cookie = collect($response->headers->getCookies())->firstWhere(fn ($cookie) => $cookie->getName() === 'laravel_maintenance');

    expect($response->getStatusCode())->toBe(302)
        ->and($cookie)->not->toBeNull()
        ->and(MaintenanceModeBypassCookie::isValid((string) $cookie->getValue(), 'let-me-in'))->toBeTrue();
});

it('keeps letting through a visitor with a valid bypass cookie', function () {
    $cookie = (string) collect(duringMaintenance('let-me-in', '/let-me-in')->headers->getCookies())
        ->firstWhere(fn ($cookie) => $cookie->getName() === 'laravel_maintenance')
        ?->getValue();

    expect(duringMaintenance('let-me-in', '/dashboard', ['laravel_maintenance' => $cookie])->getContent())->toBe('open')
        ->and(duringMaintenance('let-me-in', '/dashboard')->getStatusCode())->toBe(503)
        ->and(duringMaintenance('let-me-in', '/wrong-secret')->getStatusCode())->toBe(503);
});

it('lets through a request the platform marks with the bypass header', function (?string $secret) {
    // FastCGI hands the header to PHP as this server variable; the HTTP
    // proxy path builds the same header on the request.
    expect(duringMaintenance($secret, '/dashboard', server: ['HTTP_SKE_MAINTENANCE_BYPASS' => BYPASS_TOKEN])->getContent())->toBe('open');
})->with([
    'without a bypass secret' => [null],
    'with a bypass secret' => ['let-me-in'],
]);

it('closes the application to a request whose bypass header is not the platform\'s token', function (string $value) {
    expect(duringMaintenance('let-me-in', '/dashboard', server: ['HTTP_SKE_MAINTENANCE_BYPASS' => $value])->getStatusCode())->toBe(503);
})->with([
    'empty' => [''],
    'one' => ['1'],
    'other' => ['true'],
    'prefix' => [substr(BYPASS_TOKEN, 0, -1)],
    'longer' => [BYPASS_TOKEN.'0'],
    'other case' => [strtoupper(BYPASS_TOKEN)],
]);

it('ignores the bypass header without a platform token, as behind an adapter that sets none', function (?string $token, string $value) {
    // An adapter that sets no token may also forward a client's copy of the
    // header, so the header alone must never open the application.
    expect(duringMaintenance('let-me-in', '/dashboard', server: ['HTTP_SKE_MAINTENANCE_BYPASS' => $value], token: $token)->getStatusCode())->toBe(503);
})->with([
    'unset, header 1' => [null, '1'],
    'unset, empty header' => [null, ''],
    'empty, empty header' => ['', ''],
    'empty, header 1' => ['', '1'],
]);

it('stays in maintenance outside a request carrying the bypass header', function () {
    withSkeEnvVariable('MAINTENANCE_MODE', function () {
        $_SERVER['MAINTENANCE_MODE'] = 'true';
        $container = Container::getInstance();

        try {
            $app = new Application(sys_get_temp_dir());
            $app->instance('request', Request::create('/'));

            expect((new PlatformMaintenanceMode)->active())->toBeTrue();
        } finally {
            Container::setInstance($container);
        }
    });
});

it('refuses artisan down and up, which cannot reach other instances', function (string $method, array $arguments) {
    (new PlatformMaintenanceMode)->{$method}(...$arguments);
})->with([
    'down' => ['activate', [[]]],
    'up' => ['deactivate', []],
])->throws(RuntimeException::class, 'SKE dashboard');
