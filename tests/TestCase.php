<?php

namespace Kandita\Paydita\Tests;

use Kandita\Paydita\PayditaServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [PayditaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('paydita.base_url', 'https://pay.example.test');
        $app['config']->set('paydita.key', 'pk_sand_testkey');
        $app['config']->set('paydita.secret', 'ps_sand_testsecret');
        $app['config']->set('paydita.signing_secret', 'whsec_sand_testsecret');
        $app['config']->set('paydita.callback_url', 'https://app.example.test/paydita/events');
        $app['config']->set('cache.default', 'array');
    }
}
