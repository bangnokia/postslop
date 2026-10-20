<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Publishing;
use App\Jobs\PublishDuePosts;
use App\AccessToken;
use App\Social\BlueskyPublisher;
use App\Social\DevNullPublisher;
use App\Social\SessionStore;
use App\Social\XBrowserPublisher;
use App\Social\XPublisher;
use Crab\Contracts\Cache\Cache;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Database\Database;
use Crab\Contracts\HttpClient\Client;
use Crab\Contracts\Provider;
use Crab\Contracts\Schedule\Schedule;

/**
 * Where the application's own services are bound.
 */
final class AppProvider implements Provider
{
    public function register(Container $container): void
    {
        $container->singleton(
            AccessToken::class,
            static fn(): AccessToken => new AccessToken(config('postslop.token_path')),
        );
        $container->singleton(
            SessionStore::class,
            static fn(): SessionStore => new SessionStore(config('postslop.sessions_file')),
        );
        $container->singleton(Publishing::class, static function (Container $container): Publishing {
            $http = $container->get(Client::class);
            $sessions = $container->get(SessionStore::class);
            $x = match (config('postslop.x_transport')) {
                'browser' => new XBrowserPublisher(
                    $sessions,
                    config('postslop.node_binary'),
                    config('postslop.x_browser_worker'),
                    config('postslop.x_browser_profile'),
                    baseUrl: config('postslop.x_url'),
                ),
                'http' => new XPublisher($http, $sessions),
                default => throw new \LogicException('POSTSLOP_X_TRANSPORT must be browser or http.'),
            };
            return new Publishing(
                [
                    'x' => $x,
                    'bluesky' => new BlueskyPublisher($http, $sessions),
                    'dev-null' => new DevNullPublisher($http, $sessions),
                ],
                $container->get(Database::class),
                $container->get(Cache::class),
                config('postslop.lock_file'),
                config('postslop.media_path'),
            );
        });
        // `serve` runs the schedule and the queue when SERVER_SCHEDULE and SERVER_QUEUE are on.
        $container->get(Schedule::class)->job(PublishDuePosts::class);
    }
}
