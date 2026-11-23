<?php

namespace Tests;

use App\Support\Settings;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Tests do not need built frontend assets.
        $this->withoutVite();
        Settings::flush();
    }
}
