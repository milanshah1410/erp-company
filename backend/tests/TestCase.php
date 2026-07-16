<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The test `array` cache store is not rolled back between tests the way
        // RefreshDatabase rolls back the database. Because :memory: sqlite reuses
        // auto-increment ids after each rollback, two tests can share a tenant id
        // and therefore the same tenant-namespaced cache keys — letting one
        // test's cached settings / menu catalogue leak into the next. Flushing
        // here restores per-test isolation now that hot reads are cached (§17).
        Cache::flush();
    }
}
