<?php

namespace Tests;

use App\Support\Api\ApiContext;
use App\Support\Settings;
use App\Support\Webhooks\Webhooks;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The same random people in every run, so no test fails by chance.
        fake()->seed(crc32(static::class.'::'.$this->name()));
        // Tests do not need built frontend assets.
        $this->withoutVite();
        ApiContext::set(null);
        Settings::flush();
        Webhooks::flush();
    }
}
