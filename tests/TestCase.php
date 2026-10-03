<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase wipes whatever database is configured. A cached config (php artisan
     * config:cache) ignores phpunit.xml and would point the tests at the real database, so
     * refuse to boot unless the tests are on in-memory SQLite.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $default = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$default}.database");

        if ($default !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(
                "Refusing to run tests against the '{$default}' database. Run `php artisan config:clear` first "
                .'(a cached config ignores phpunit.xml).'
            );
        }

        return $app;
    }
}
