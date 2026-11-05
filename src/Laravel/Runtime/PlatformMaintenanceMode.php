<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Runtime;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Http\Request;
use RuntimeException;
use Ske\Bridge\Runtime\Environment;

/**
 * Laravel's maintenance mode, driven by the platform. SKE puts an environment
 * into maintenance by redeploying it with MAINTENANCE_MODE=true, so every
 * instance sees the same state. Laravel's own PreventRequestsDuringMaintenance
 * then answers with the application's 503 page, and the scheduler skips tasks
 * not marked evenInMaintenanceMode(), exactly as after `artisan down`.
 *
 * `artisan down` and `artisan up` can't work here: each instance has its own
 * filesystem and is replaced on the next deploy.
 *
 * A MAINTENANCE_MODE_SECRET variable stands in for `artisan down --secret`:
 * Laravel then lets through whoever visits /{secret} and keeps them in with
 * its bypass cookie.
 *
 * The platform also lets through the addresses on the environment's access
 * list: it marks their requests with an `SKE-Maintenance-Bypass` header
 * carrying the token it puts in SKE_MAINTENANCE_BYPASS_TOKEN, after removing
 * any copy a client sent. During a request whose header equals that token the
 * application is not in maintenance. Without a token the header is ignored,
 * so a client's copy passed along by a platform that sets none opens nothing.
 * The current request is read from the container on each check, so a
 * long-lived worker sees the request it is serving now.
 */
final class PlatformMaintenanceMode implements MaintenanceMode
{
    private const BYPASS_HEADER = 'SKE-Maintenance-Bypass';

    private const BYPASS_TOKEN = 'SKE_MAINTENANCE_BYPASS_TOKEN';

    /** @param  array<string, mixed>  $payload */
    public function activate(array $payload): void
    {
        throw new RuntimeException('Turn on maintenance mode for this environment in the SKE dashboard instead.');
    }

    public function deactivate(): void
    {
        throw new RuntimeException('Turn off maintenance mode for this environment in the SKE dashboard instead.');
    }

    public function active(): bool
    {
        return Environment::get('MAINTENANCE_MODE') === 'true' && ! self::bypassedByPlatform();
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $secret = Environment::get('MAINTENANCE_MODE_SECRET');

        return $secret === null ? [] : ['secret' => $secret];
    }

    private static function bypassedByPlatform(): bool
    {
        $token = Environment::get(self::BYPASS_TOKEN);

        if ($token === null) {
            return false;
        }

        $container = Container::getInstance();

        if (! $container->bound('request')) {
            return false;
        }

        $request = $container->make('request');

        if (! $request instanceof Request) {
            return false;
        }

        $value = $request->headers->get(self::BYPASS_HEADER);

        return is_string($value) && hash_equals($token, $value);
    }
}
