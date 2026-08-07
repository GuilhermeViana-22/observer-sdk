<?php

declare(strict_types=1);

namespace Observer\Tests;

use Observer\Collectors\AbstractCollector;
use Observer\ObserverServiceProvider;
use Observer\Testing\ObserverFake;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Estado estático compartilhado entre testes precisa ser zerado.
        AbstractCollector::forgetSeenExceptions();
    }

    protected function tearDown(): void
    {
        ObserverFake::reset();

        parent::tearDown();
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ObserverServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('observer.enabled', true);
        $app['config']->set('observer.environments', []);
        $app['config']->set('observer.transport.driver', 'memory');
        $app['config']->set('observer.buffer.enabled', false);
        $app['config']->set('observer.sample_rate', 1.0);
        $app['config']->set('observer.sample_rates', []);
        $app['config']->set('observer.collectors.commands.enabled', true);
    }
}
