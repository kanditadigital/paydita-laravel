<?php

namespace Kandita\Paydita;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use Kandita\Paydita\Commands\PingCommand;
use Kandita\Paydita\Support\Signer;
use Kandita\Paydita\Webhook\WebhookVerifier;

class PayditaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/paydita.php', 'paydita');

        $this->app->singleton(PayditaClient::class, fn ($app): PayditaClient => new PayditaClient(
            $app->make(Factory::class),
            new Signer,
            $app['config']->get('paydita'),
        ));

        $this->app->bind(WebhookVerifier::class, fn ($app): WebhookVerifier => new WebhookVerifier(
            (string) $app['config']->get('paydita.signing_secret'),
            (int) $app['config']->get('paydita.webhook.tolerance_seconds', 300),
        ));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/paydita.php' => config_path('paydita.php')], 'paydita-config');

        if ($this->app['config']->get('paydita.webhook.enabled')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/webhook.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([PingCommand::class]);
        }
    }
}
